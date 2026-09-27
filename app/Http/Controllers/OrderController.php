<?php

namespace App\Http\Controllers;

use App\Models\OrderState;
use App\Models\Product;
use App\Services\Moysklad\CustomerOrderSyncService;
use App\Services\Moysklad\StockSyncService;
use App\Services\OrderChangeService;
use App\Services\OrderPositionService;
use App\Services\OrderPriorityService;
use App\Services\OrderProductionService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function __construct(
        private OrderService $service,
        private CustomerOrderSyncService $sync,
        private StockSyncService $stockSync,
        private OrderProductionService $production,
    ) {
    }

    public function index(Request $request): View
    {
        return view('orders.index', $this->service->getIndexData($request));
    }

    public function show(Request $request, string $moyskladId): View
    {
        return view('orders.show', $this->service->getShowData($request, $moyskladId));
    }

    /**
     * Перевести заявку в другой статус — с записью в МойСклад.
     */
    public function updateState(Request $request, OrderChangeService $changes, string $moyskladId): RedirectResponse
    {
        $data = $request->validate([
            'state_id' => 'required|string|exists:order_states,id',
        ]);

        $order = $this->service->findForUser($request, $moyskladId);

        // Выставить можно только используемый статус: иначе заявка выпадет
        // из выгрузки и будет удалена при следующей синхронизации.
        $state = OrderState::enabled()->find($data['state_id']);
        if (! $state) {
            return back()->withErrors(['state_id' => 'Этот статус не используется в программе.']);
        }

        if ($order->state_moysklad_id === $state->id) {
            return back()->with('warning', 'Заявка уже в статусе «' . $state->name . '».');
        }

        $previousStateId = $order->state_moysklad_id;
        $result = $this->sync->updateState($order, $state);

        // Окно производства открывается/закрывается только после успешной записи
        // в МойСклад — иначе снимок остатка лёг бы под статус, которого там нет.
        if ($result['success']) {
            $changes->rememberStateBeforeChange($order, $previousStateId, $state->id);
            $this->production->syncPeriod($order, $state->id);
        }

        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Назначить заявке отделы — с записью в МойСклад. Пустой выбор снимает все отделы.
     */
    public function updateDepartments(Request $request, string $moyskladId): RedirectResponse
    {
        $data = $request->validate([
            'departments'   => 'nullable|array',
            'departments.*' => 'integer|exists:departments,id',
        ]);

        $order = $this->service->findForUser($request, $moyskladId);

        $result = $this->sync->updateDepartments($order, $data['departments'] ?? []);

        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Настроить позицию заявки: склады комплектации и уточнения мастера.
     */
    public function storePosition(
        Request $request,
        OrderPositionService $positions,
        string $moyskladId,
    ): RedirectResponse {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'stores'     => 'required|array|min:1',
            'stores.*'   => 'string|exists:stores,id',
            'fact'       => 'nullable|numeric|min:0',
            'produced'   => 'nullable|numeric|min:0',
            'note'       => 'nullable|string|max:500',
        ], [
            'stores.required' => 'Выберите хотя бы один склад',
            'stores.min'      => 'Выберите хотя бы один склад',
            'fact.min'        => 'Остаток не может быть отрицательным',
            'produced.min'    => 'Изготовлено не может быть отрицательным',
        ]);

        $order   = $this->service->findForUser($request, $moyskladId, ['items']);
        $product = Product::findOrFail($data['product_id']);
        $share   = $this->service->allocate(collect([$order]), $request->user())[$order->id][$product->id] ?? null;

        $positions->save(
            $order,
            $product,
            $data['stores'],
            isset($data['fact']) ? (float) $data['fact'] : null,
            isset($data['produced']) ? (float) $data['produced'] : null,
            $data['note'] ?? null,
            $request->user(),
            $share['produced'] ?? $this->production->producedForOrder($order)[$product->id] ?? [],
            $share['stock'] ?? null,
        );

        return redirect()->route('orders.show', $moyskladId)
            ->with('success', 'Позиция «' . $product->name . '» обновлена.');
    }

    /**
     * Пересчитать заявку по складам: свежие остатки из МойСклад, новый снимок,
     * изготовленное с нуля.
     */
    public function recalculate(Request $request, string $moyskladId): RedirectResponse
    {
        $order = $this->service->findForUser($request, $moyskladId, ['items.product']);

        $this->production->recalculate($order);

        return redirect()->route('orders.show', $moyskladId)
            ->with('success', 'Остатки пересчитаны по складам, отсчёт изготовленного начат заново.');
    }

    /**
     * Отметить позицию готовой или снять отметку — AJAX из списка заявок.
     */
    public function updateReady(
        Request $request,
        OrderPositionService $positions,
        string $moyskladId,
        int $productId,
    ): JsonResponse {
        $data = $request->validate([
            'ready' => 'required|boolean',
        ]);

        $order = $this->service->findForUser($request, $moyskladId, ['items']);

        abort_unless($order->items->contains('product_id', $productId), 404);

        $setting = $positions->setReady($order, $productId, (bool) $data['ready'], $request->user());

        return response()->json([
            'success' => true,
            'ready'   => $setting->isReady(),
        ]);
    }

    /**
     * Скрыть позицию для отдела или вернуть её — AJAX из карточки заявки.
     * Скрытая позиция пропадает из списка заявок у этого отдела.
     */
    public function updateHidden(
        Request $request,
        OrderPositionService $positions,
        string $moyskladId,
        int $productId,
    ): JsonResponse {
        $data = $request->validate([
            'department_id' => 'required|integer',
            'hidden'        => 'required|boolean',
        ]);

        $order = $this->service->findForUser($request, $moyskladId, ['items']);

        abort_unless($order->items->contains('product_id', $productId), 404);

        if (! $order->departments->contains('id', $data['department_id'])) {
            throw ValidationException::withMessages(['department_id' => 'Отдел не относится к заявке.']);
        }

        abort_unless(
            $this->service->hideableDepartments($order, $request->user())->contains('id', $data['department_id']),
            403,
        );

        $setting = $positions->setHidden($order, $productId, (int) $data['department_id'], (bool) $data['hidden']);

        return response()->json([
            'success'   => true,
            'hiddenFor' => $setting->hiddenDepartmentIds(),
        ]);
    }

    public function destroyPosition(
        Request $request,
        OrderPositionService $positions,
        string $moyskladId,
        int $productId,
    ): RedirectResponse {
        $order = $this->service->findForUser($request, $moyskladId);

        $positions->reset($order, $productId);

        return redirect()->route('orders.show', $moyskladId)
            ->with('success', 'Уточнения сняты — показаны расчётные значения.');
    }

    /**
     * Сдвинуть заявку в очереди на одну позицию. Фильтры списка приходят в query
     * string: соседом считается заявка, которую пользователь видит выше/ниже.
     */
    public function movePriority(
        Request $request,
        OrderPriorityService $priority,
        string $moyskladId,
    ): RedirectResponse {
        $data = $request->validate([
            'direction' => 'required|in:' . OrderPriorityService::UP . ',' . OrderPriorityService::DOWN,
        ]);

        $order = $this->service->findForUser($request, $moyskladId);

        if (! $priority->move($order, $data['direction'], $request)) {
            return back()->with('warning', 'Заявка «' . $order->name . '» уже '
                . ($data['direction'] === OrderPriorityService::UP ? 'первая' : 'последняя') . ' в очереди.');
        }

        return back();
    }

    public function updateUrgent(
        Request $request,
        OrderPriorityService $priority,
        string $moyskladId,
    ): RedirectResponse {
        $data = $request->validate([
            'urgent' => 'required|boolean',
        ]);

        $order = $this->service->findForUser($request, $moyskladId);

        $priority->setUrgent($order, (bool) $data['urgent']);

        return back()->with('success', $order->is_urgent
            ? 'Заявка «' . $order->name . '» отмечена срочной.'
            : 'Отметка «срочно» снята с заявки «' . $order->name . '».');
    }

    /**
     * «Принято»: мастер увидел изменение состава. Заявку в статусе «Изменено» возвращаем
     * в прежний статус — сначала в МойСклад; не записалось — изменения остаются на виду.
     */
    public function acknowledgeChanges(
        Request $request,
        OrderChangeService $changes,
        string $moyskladId,
    ): RedirectResponse {
        $order = $this->service->findForUser($request, $moyskladId);

        $message = 'Изменения заявки «' . $order->name . '» приняты.';

        if ($changes->isInChangedState($order)) {
            $state = $changes->returnState($order);
            if (! $state) {
                return back()->with('error', 'Не выбран производственный статус — некуда вернуть заявку. Отметьте его в админке статусов.');
            }

            $result = $this->sync->updateState($order, $state);
            if (! $result['success']) {
                return back()->with('error', $result['message']);
            }

            $this->production->syncPeriod($order, $state->id);
            $message .= ' Статус — «' . $state->name . '».';
        }

        $changes->acknowledge($order);

        return back()->with('success', $message);
    }

    public function resetPriority(
        Request $request,
        OrderPriorityService $priority,
        string $moyskladId,
    ): RedirectResponse {
        $order = $this->service->findForUser($request, $moyskladId);

        $priority->resetManual($order);

        return back()->with('success', 'Заявка «' . $order->name . '» возвращена на место по сроку отгрузки.');
    }

    public function sync(): RedirectResponse
    {
        $orders = $this->sync->pullActive();
        $stocks = $this->stockSync->syncAllProductsStocksByStores();

        $message = "Заявки: {$orders['message']} Остатки: {$stocks['message']}";
        $flashKey = ($orders['success'] && $stocks['success']) ? 'success' : 'error';

        return redirect()->route('orders.index')->with($flashKey, $message);
    }
}
