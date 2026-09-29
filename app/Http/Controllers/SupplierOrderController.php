<?php

namespace App\Http\Controllers;

use App\Http\Requests\SupplierOrder\StoreSupplierOrderRequest;
use App\Http\Requests\SupplierOrder\UpdateSupplierOrderRequest;
use App\Models\Setting;
use App\Models\SupplierOrder;
use App\Services\Moysklad\SupplierOrderSyncService;
use App\Services\SupplierOrderService;
use App\Support\DepartmentAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

// рефакторинг v2 от 26.04.2026  controller -> service -> service/moysklad 

class SupplierOrderController extends Controller
{
    public function __construct(
        private SupplierOrderService $service,
        private SupplierOrderSyncService $syncService,
    ) {}

    public function index(Request $request): View
    {
        return view('supplier-orders.index', $this->service->getIndexData($request));
    }

    public function show(SupplierOrder $supplierOrder): View
    {
        $supplierOrder->load(['counterparty', 'store', 'receiver', 'items.product', 'createdBy.worker']);
        return view('supplier-orders.show', compact('supplierOrder'));
    }

    public function create(Request $request): View
    {
        ['stores' => $stores, 'counterparties' => $counterparties, 'receivers' => $receivers]
            = $this->service->getFormOptions();

        $currentWorker = auth()->user()?->worker;
        $department    = $currentWorker?->department;
        $defaultStore  = ($department && $department->default_raw_store_id)
            ? Setting::deptRawStore($department, $stores)
            : $stores->first(fn($s) => mb_stripos($s->name, 'сырь') !== false);
        $defaultReceiver = $receivers->firstWhere('id', $currentWorker?->id);
        $recentOrders    = $this->service->getRecentOrders(20, $request);

        $copyFrom = $request->filled('copy_from')
            ? $this->service->getCopySource($request->copy_from)
            : null;

        return view('supplier-orders.create', compact(
            'stores',
            'counterparties',
            'receivers',
            'defaultStore',
            'defaultReceiver',
            'recentOrders',
            'copyFrom'
        ));
    }

    public function store(StoreSupplierOrderRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['created_by_user_id'] = auth()->id();

        if ($error = $this->departmentError($request, $data)) {
            return back()->withErrors($error)->withInput();
        }

        $order      = $this->service->create($data, auth()->user()?->isAdmin() ?? false);
        $syncResult = $this->syncService->syncOrderToMoysklad($order);

        if ($syncResult['success']) {
            return redirect()->route('supplier-orders.index')
                ->with('success', "Поступление №{$order->number} создано и передано в МойСклад.");
        }

        return redirect()->route('supplier-orders.index')
            ->with('error', "Поступление №{$order->number} сохранено, но не передано в МойСклад: {$syncResult['message']}");
    }

    public function edit(SupplierOrder $supplierOrder): View|RedirectResponse
    {
        $this->authorize('modify', $supplierOrder);

        if (!$supplierOrder->isNew()) {
            return redirect()->route('supplier-orders.index')
                ->with('warning', 'Редактировать можно только поступления в статусе «Новый».');
        }

        ['stores' => $stores, 'counterparties' => $counterparties, 'receivers' => $receivers]
            = $this->service->getFormOptions();

        $defaultStore = $stores->first(fn($s) => mb_stripos($s->name, 'сырь') !== false);
        $supplierOrder->load(['counterparty', 'store', 'items.product']);

        return view('supplier-orders.edit', compact(
            'supplierOrder',
            'stores',
            'counterparties',
            'receivers',
            'defaultStore'
        ));
    }

    public function update(UpdateSupplierOrderRequest $request, SupplierOrder $supplierOrder): RedirectResponse
    {
        $this->authorize('modify', $supplierOrder);

        if (!$supplierOrder->isNew()) {
            return redirect()->route('supplier-orders.index')
                ->with('warning', 'Редактировать можно только поступления в статусе «Новый».');
        }

        if ($error = $this->departmentError($request, $request->validated(), $supplierOrder)) {
            return back()->withErrors($error)->withInput();
        }

        $order      = $this->service->update($supplierOrder, $request->validated(), auth()->user()?->isAdmin() ?? false);
        $syncResult = $this->syncService->updateOrderInMoysklad($order);

        if ($syncResult['success']) {
            return redirect()->route('supplier-orders.index')
                ->with('success', "Поступление №{$order->number} обновлено и передано в МойСклад.");
        }

        return redirect()->route('supplier-orders.index')
            ->with('error', "Поступление №{$order->number} сохранено, но не передано в МойСклад: {$syncResult['message']}");
    }

    public function destroy(SupplierOrder $supplierOrder): RedirectResponse
    {
        $this->authorize('modify', $supplierOrder);

        $isAdmin = auth()->user()?->isAdmin() ?? false;

        if (!$isAdmin && !$supplierOrder->isNew()) {
            return redirect()->route('supplier-orders.index')
                ->with('warning', 'Удалить можно только поступления в статусе «Новый».');
        }

        $number = $supplierOrder->number;

        if ($supplierOrder->moysklad_id || $supplierOrder->supply_moysklad_id) {
            $result = $this->syncService->deleteOrderWithSupply($supplierOrder);
            if (!$result['success']) {
                return redirect()->route('supplier-orders.show', $supplierOrder)
                    ->with('error', "Не удалось удалить поступление №{$number} из МойСклад: {$result['message']}");
            }
        }

        $this->service->delete($supplierOrder);

        return redirect()->route('supplier-orders.index')
            ->with('success', "Поступление №{$number} удалено.");
    }

    public function sync(SupplierOrder $supplierOrder): RedirectResponse
    {
        $this->authorize('modify', $supplierOrder);

        if ($supplierOrder->status === SupplierOrder::STATUS_SENT) {
            return redirect()->route('supplier-orders.show', $supplierOrder)
                ->with('warning', 'Приёмка уже создана в МойСклад.');
        }

        $result = $this->syncService->initiateSync($supplierOrder);

        if ($result['status'] === 'confirm_needed') {
            session()->put("sync_confirm_{$supplierOrder->id}", [
                'issue'          => $result['issue'],
                'suggested_name' => $result['suggested_name'],
            ]);
            return redirect()->route('supplier-orders.sync-confirm', $supplierOrder);
        }

        if ($result['status'] === 'success') {
            return redirect()->route('supplier-orders.index')
                ->with('success', "Приёмка по поступлению №{$supplierOrder->number} создана в МойСклад.");
        }

        return redirect()->route('supplier-orders.index')
            ->with('error', "Не удалось создать приёмку для №{$supplierOrder->number}: {$result['message']}");
    }

    public function syncConfirm(SupplierOrder $supplierOrder): View|RedirectResponse
    {
        $this->authorize('modify', $supplierOrder);

        $confirm = session()->get("sync_confirm_{$supplierOrder->id}");
        if (!$confirm) {
            return redirect()->route('supplier-orders.index');
        }

        return view('supplier-orders.sync-confirm', [
            'order'     => $supplierOrder,
            'issue'     => $confirm['issue'],
            'suggested' => $confirm['suggested_name'],
        ]);
    }

    public function forceSync(Request $request, SupplierOrder $supplierOrder): RedirectResponse
    {
        $this->authorize('modify', $supplierOrder);

        if ($supplierOrder->status === SupplierOrder::STATUS_SENT) {
            return redirect()->route('supplier-orders.show', $supplierOrder)
                ->with('warning', 'Приёмка уже создана в МойСклад.');
        }

        // Без mode (форма без кнопки режима, прямой запрос) — отмена, а не TypeError в сервисе
        $mode = (string) $request->input('mode', '');
        session()->forget("sync_confirm_{$supplierOrder->id}");

        $result = $this->syncService->forceSync($supplierOrder, $mode, $request->input('suggested_name'));

        if ($result['status'] === 'cancelled') {
            return redirect()->route('supplier-orders.index')
                ->with('warning', 'Действие отменено.');
        }

        if ($result['status'] === 'error') {
            $route = $mode === 'create_order_only' ? 'supplier-orders.show' : 'supplier-orders.index';
            return $mode === 'create_order_only'
                ? redirect()->route($route, $supplierOrder)->with('error', $result['message'])
                : redirect()->route($route)->with('error', $result['message']);
        }

        return match ($mode) {
            'create_order_only' => redirect()->route('supplier-orders.show', $supplierOrder)
                ->with('success', "Заказ поставщику №{$result['number']} создан в МойСклад. Теперь можно создать Приёмку."),
            'recreate' => redirect()->route('supplier-orders.index')
                ->with('success', "Заказ поставщику и Приёмка №{$result['number']} созданы в МойСклад."),
            'suffix_supply' => redirect()->route('supplier-orders.index')
                ->with('success', "Приёмка создана в МойСклад с именем «{$result['name']}». Номер поступления обновлён."),
            default => redirect()->route('supplier-orders.index')
                ->with('warning', 'Действие отменено.'),
        };
    }

    public function nextOrderNumber(): JsonResponse
    {
        return response()->json(['number' => $this->service->nextOrderNumber()]);
    }

    /**
     * Отдел поступления берётся из приёмщика (поля отдела в форме нет): не-админ не
     * создаёт поступление без отдела или в чужом отделе и не переносит его в чужой.
     *
     * @return array<string, string>|null
     */
    private function departmentError(Request $request, array $data, ?SupplierOrder $order = null): ?array
    {
        $departmentId = $this->service->resolveDepartmentId($data, $order);

        // Правка без смены отдела — не перенос: старое поступление без отдела править можно
        $unchanged = $order !== null && $departmentId === ($order->department_id ? (int) $order->department_id : null);

        if ($unchanged || DepartmentAccess::canAssign($request->user(), $departmentId)) {
            return null;
        }

        return [
            'receiver_id' => $departmentId === null
                ? 'Выберите приёмщика с отделом — по нему определяется отдел поступления.'
                : 'Приёмщик из другого отдела — выберите приёмщика своего отдела.',
        ];
    }
}
