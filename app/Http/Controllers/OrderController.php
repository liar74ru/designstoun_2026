<?php

namespace App\Http\Controllers;

use App\Models\OrderState;
use App\Models\Product;
use App\Services\Moysklad\CustomerOrderSyncService;
use App\Services\Moysklad\StockSyncService;
use App\Services\OrderPositionService;
use App\Services\OrderProductionService;
use App\Services\OrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function updateState(Request $request, string $moyskladId): RedirectResponse
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

        $result = $this->sync->updateState($order, $state);

        // Окно производства открывается/закрывается только после успешной записи
        // в МойСклад — иначе снимок остатка лёг бы под статус, которого там нет.
        if ($result['success']) {
            $this->production->syncPeriod($order, $state->id);
        }

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

        $positions->save(
            $order,
            $product,
            $data['stores'],
            isset($data['fact']) ? (float) $data['fact'] : null,
            isset($data['produced']) ? (float) $data['produced'] : null,
            $data['note'] ?? null,
            $request->user(),
            $this->production->producedForOrder($order)[$product->id] ?? [],
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

    public function sync(): RedirectResponse
    {
        $orders = $this->sync->pullActive();
        $stocks = $this->stockSync->syncAllProductsStocksByStores();

        $message = "Заявки: {$orders['message']} Остатки: {$stocks['message']}";
        $flashKey = ($orders['success'] && $stocks['success']) ? 'success' : 'error';

        return redirect()->route('orders.index')->with($flashKey, $message);
    }
}
