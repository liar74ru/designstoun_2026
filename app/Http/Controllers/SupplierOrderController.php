<?php

namespace App\Http\Controllers;

use App\Http\Requests\SupplierOrder\StoreSupplierOrderRequest;
use App\Http\Requests\SupplierOrder\UpdateSupplierOrderRequest;
use App\Models\SupplierOrder;
use App\Services\Moysklad\SupplierOrderSyncService;
use App\Services\SupplierOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

# рефакторинг v2 от 26.04.2026  controller -> service -> service/moysklad 

class SupplierOrderController extends Controller
{
    public function __construct(
        private SupplierOrderService $service,
        private SupplierOrderSyncService $syncService,
    ) {}

    public function index(): View
    {
        $orders = SupplierOrder::with(['counterparty', 'store', 'receiver', 'items.product'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('supplier-orders.index', compact('orders'));
    }

    public function show(SupplierOrder $supplierOrder): View
    {
        $supplierOrder->load(['counterparty', 'store', 'receiver', 'items.product']);
        return view('supplier-orders.show', compact('supplierOrder'));
    }

    public function create(Request $request): View
    {
        ['stores' => $stores, 'counterparties' => $counterparties, 'receivers' => $receivers]
            = $this->service->getFormOptions();

        $defaultStore    = $stores->first(fn($s) => mb_stripos($s->name, 'сырь') !== false);
        $currentWorker   = auth()->user()?->worker;
        $defaultReceiver = $receivers->firstWhere('id', $currentWorker?->id);
        $recentOrders    = $this->service->getRecentOrders(20);

        $copyFrom = $request->filled('copy_from')
            ? $this->service->getCopySource((int) $request->copy_from)
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
        $order      = $this->service->create($request->validated(), auth()->user()?->isAdmin() ?? false);
        $syncResult = $this->syncService->syncOrderToMoysklad($order);

        if ($syncResult['success']) {
            return redirect()->route('supplier-orders.index')
                ->with('success', "Поступление №{$order->number} создано и передано в МойСклад.");
        }

        return redirect()->route('supplier-orders.index')
            ->with('danger', "Поступление №{$order->number} сохранено, но не передано в МойСклад: {$syncResult['message']}");
    }

    public function edit(SupplierOrder $supplierOrder): View|RedirectResponse
    {
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
        if (!$supplierOrder->isNew()) {
            return redirect()->route('supplier-orders.index')
                ->with('warning', 'Редактировать можно только поступления в статусе «Новый».');
        }

        $order      = $this->service->update($supplierOrder, $request->validated(), auth()->user()?->isAdmin() ?? false);
        $syncResult = $this->syncService->updateOrderInMoysklad($order);

        if ($syncResult['success']) {
            return redirect()->route('supplier-orders.index')
                ->with('success', "Поступление №{$order->number} обновлено и передано в МойСклад.");
        }

        return redirect()->route('supplier-orders.index')
            ->with('danger', "Поступление №{$order->number} сохранено, но не передано в МойСклад: {$syncResult['message']}");
    }

    public function destroy(SupplierOrder $supplierOrder): RedirectResponse
    {
        if (!$supplierOrder->isNew()) {
            return redirect()->route('supplier-orders.index')
                ->with('warning', 'Удалить можно только поступления в статусе «Новый».');
        }

        $number = $supplierOrder->number;

        if ($supplierOrder->moysklad_id) {
            $result = $this->syncService->deleteOrderFromMoysklad($supplierOrder->moysklad_id);
            if (!$result['success']) {
                return redirect()->route('supplier-orders.index')
                    ->with('danger', "Не удалось удалить поступление №{$number} из МойСклад: {$result['message']}");
            }
        }

        $this->service->delete($supplierOrder);

        return redirect()->route('supplier-orders.index')
            ->with('success', "Поступление №{$number} удалено.");
    }

    public function sync(SupplierOrder $supplierOrder): RedirectResponse
    {
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
            ->with('danger', "Не удалось создать приёмку для №{$supplierOrder->number}: {$result['message']}");
    }

    public function syncConfirm(SupplierOrder $supplierOrder): View|RedirectResponse
    {
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
        if ($supplierOrder->status === SupplierOrder::STATUS_SENT) {
            return redirect()->route('supplier-orders.show', $supplierOrder)
                ->with('warning', 'Приёмка уже создана в МойСклад.');
        }

        $mode = $request->input('mode');
        session()->forget("sync_confirm_{$supplierOrder->id}");

        $result = $this->syncService->forceSync($supplierOrder, $mode, $request->input('suggested_name'));

        if ($result['status'] === 'cancelled') {
            return redirect()->route('supplier-orders.index')
                ->with('warning', 'Действие отменено.');
        }

        if ($result['status'] === 'error') {
            $route = $mode === 'create_order_only' ? 'supplier-orders.show' : 'supplier-orders.index';
            return $mode === 'create_order_only'
                ? redirect()->route($route, $supplierOrder)->with('danger', $result['message'])
                : redirect()->route($route)->with('danger', $result['message']);
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
}
