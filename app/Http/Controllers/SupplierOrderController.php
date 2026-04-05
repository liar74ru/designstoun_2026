<?php

namespace App\Http\Controllers;

use App\Models\Counterparty;
use App\Models\Store;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderItem;
use App\Models\Worker;
use App\Services\MoySkladPurchaseOrderService;
use App\Services\MoySkladSupplyService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SupplierOrderController extends Controller
{
    public function __construct(
        private MoySkladPurchaseOrderService $purchaseOrderService,
        private MoySkladSupplyService $supplyService,
    ) {
    }

    public function index(): View
    {
        $orders = SupplierOrder::with(['counterparty', 'store', 'receiver', 'items.product'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('supplier-orders.index', compact('orders'));
    }

    public function create(Request $request): View
    {
        $stores         = Store::where('archived', false)->orderBy('name')->get();
        $counterparties = Counterparty::orderBy('name')->get();
        $receivers      = Worker::whereIn('position', ['Директор', 'Администратор', 'Мастер', 'Кладовщик'])
            ->orderBy('name')
            ->get();

        $defaultStore    = $stores->first(fn($s) => mb_stripos($s->name, 'сырь') !== false);
        $currentWorker   = auth()->user()?->worker;
        $defaultReceiver = $receivers->firstWhere('id', $currentWorker?->id);

        $recentOrders = SupplierOrder::with(['counterparty', 'store', 'items.product'])
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        $copyFrom = null;
        if ($request->filled('copy_from')) {
            $copyFrom = SupplierOrder::with(['counterparty', 'items.product'])
                ->find($request->copy_from);
        }

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

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'store_id'          => 'required|exists:stores,id',
            'counterparty_id'   => 'required|exists:counterparties,id',
            'receiver_id'       => 'nullable|exists:workers,id',
            'number'            => 'required|string|max:100',
            'note'              => 'nullable|string|max:1000',
            'manual_created_at' => 'nullable|date',
            'products'          => 'required|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity'   => 'required|numeric|min:0.001',
        ]);

        $createdAt = (auth()->user()?->isAdmin() && !empty($data['manual_created_at']))
            ? Carbon::parse($data['manual_created_at'])
            : now();

        DB::transaction(function () use ($data, $createdAt, &$order) {
            $order = SupplierOrder::create([
                'number'          => $data['number'],
                'store_id'        => $data['store_id'],
                'counterparty_id' => $data['counterparty_id'],
                'receiver_id'     => $data['receiver_id'] ?? null,
                'note'            => $data['note'] ?? null,
                'status'          => SupplierOrder::STATUS_NEW,
                'created_at'      => $createdAt,
                'updated_at'      => $createdAt,
            ]);

            foreach ($data['products'] as $item) {
                SupplierOrderItem::create([
                    'supplier_order_id' => $order->id,
                    'product_id'        => $item['product_id'],
                    'quantity'          => $item['quantity'],
                ]);
            }
        });

        // Синхронизация с МойСклад (вне транзакции — не блокируем сохранение при ошибке API)
        $order->load(['counterparty', 'store', 'items.product']);
        $syncResult = $this->purchaseOrderService->createPurchaseOrder($order);

        if ($syncResult['success']) {
            $order->update([
                'moysklad_id' => $syncResult['moysklad_id'],
                'status'      => SupplierOrder::STATUS_NEW,
                'sync_error'  => null,
            ]);
            return redirect()->route('supplier-orders.index')
                ->with('success', "Поступление №{$order->number} создано и передано в МойСклад.");
        }

        $order->update([
            'status'     => SupplierOrder::STATUS_ERROR,
            'sync_error' => $syncResult['message'],
        ]);
        return redirect()->route('supplier-orders.index')
            ->with('danger', "Поступление №{$order->number} сохранено, но не передано в МойСклад: {$syncResult['message']}");
    }

    public function edit(SupplierOrder $supplierOrder): View|\Illuminate\Http\RedirectResponse
    {
        if (!$supplierOrder->isNew()) {
            return redirect()->route('supplier-orders.index')
                ->with('warning', 'Редактировать можно только поступления в статусе «Новый».');
        }

        $stores         = Store::where('archived', false)->orderBy('name')->get();
        $counterparties = Counterparty::orderBy('name')->get();
        $receivers      = Worker::whereIn('position', ['Директор', 'Администратор', 'Мастер', 'Кладовщик'])
            ->orderBy('name')
            ->get();

        $defaultStore = $stores->first(fn($s) => mb_stripos($s->name, 'сырь') !== false);

        $supplierOrder->load(['counterparty', 'store', 'items.product']);

        return view('supplier-orders.edit', compact('supplierOrder', 'stores', 'counterparties', 'receivers', 'defaultStore'));
    }

    public function update(Request $request, SupplierOrder $supplierOrder): \Illuminate\Http\RedirectResponse
    {
        if (!$supplierOrder->isNew()) {
            return redirect()->route('supplier-orders.index')
                ->with('warning', 'Редактировать можно только поступления в статусе «Новый».');
        }

        $data = $request->validate([
            'store_id'          => 'required|exists:stores,id',
            'counterparty_id'   => 'required|exists:counterparties,id',
            'receiver_id'       => 'nullable|exists:workers,id',
            'number'            => 'required|string|max:100',
            'note'              => 'nullable|string|max:1000',
            'manual_created_at' => 'nullable|date',
            'products'          => 'required|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity'   => 'required|numeric|min:0.001',
        ]);

        $createdAt = (auth()->user()?->isAdmin() && !empty($data['manual_created_at']))
            ? Carbon::parse($data['manual_created_at'])
            : $supplierOrder->created_at;

        DB::transaction(function () use ($data, $createdAt, $supplierOrder) {
            $supplierOrder->update([
                'number'          => $data['number'],
                'store_id'        => $data['store_id'],
                'counterparty_id' => $data['counterparty_id'],
                'receiver_id'     => $data['receiver_id'] ?? null,
                'note'            => $data['note'] ?? null,
                'created_at'      => $createdAt,
            ]);

            $supplierOrder->items()->delete();
            foreach ($data['products'] as $item) {
                SupplierOrderItem::create([
                    'supplier_order_id' => $supplierOrder->id,
                    'product_id'        => $item['product_id'],
                    'quantity'          => $item['quantity'],
                ]);
            }
        });

        // Синхронизация с МойСклад (вне транзакции)
        $supplierOrder->load(['counterparty', 'store', 'items.product']);
        $syncResult = $this->purchaseOrderService->updatePurchaseOrder($supplierOrder);

        if ($syncResult['success']) {
            // Если был статус error — сбрасываем на new после успешного обновления
            if ($supplierOrder->status === SupplierOrder::STATUS_ERROR) {
                $supplierOrder->update(['status' => SupplierOrder::STATUS_NEW, 'sync_error' => null]);
            } else {
                $supplierOrder->update(['sync_error' => null]);
            }
            return redirect()->route('supplier-orders.index')
                ->with('success', "Поступление №{$supplierOrder->number} обновлено и передано в МойСклад.");
        }

        $supplierOrder->update([
            'status'     => SupplierOrder::STATUS_ERROR,
            'sync_error' => $syncResult['message'],
        ]);
        return redirect()->route('supplier-orders.index')
            ->with('danger', "Поступление №{$supplierOrder->number} сохранено, но не передано в МойСклад: {$syncResult['message']}");
    }

    public function destroy(SupplierOrder $supplierOrder): \Illuminate\Http\RedirectResponse
    {
        if (!$supplierOrder->isNew()) {
            return redirect()->route('supplier-orders.index')
                ->with('warning', 'Удалить можно только поступления в статусе «Новый».');
        }

        $number = $supplierOrder->number;

        // Удаляем из МойСклад если есть moysklad_id
        if ($supplierOrder->moysklad_id) {
            $result = $this->purchaseOrderService->deletePurchaseOrder($supplierOrder->moysklad_id);
            if (!$result['success']) {
                return redirect()->route('supplier-orders.index')
                    ->with('danger', "Не удалось удалить поступление №{$number} из МойСклад: {$result['message']}");
            }
        }

        $supplierOrder->items()->delete();
        $supplierOrder->delete();

        return redirect()->route('supplier-orders.index')
            ->with('success', "Поступление №{$number} удалено.");
    }

    /**
     * Создать Приёмку в МойСклад на основе Заказа поставщику.
     * Перед созданием проверяет наличие Заказа поставщику в МойСклад
     * и обрабатывает коллизии с дублирующимися именами.
     */
    public function sync(SupplierOrder $supplierOrder): RedirectResponse
    {
        if ($supplierOrder->status !== SupplierOrder::STATUS_NEW) {
            return redirect()->route('supplier-orders.index')
                ->with('warning', 'Создать приёмку можно только для поступлений в статусе «Новый».');
        }

        $supplierOrder->load(['counterparty', 'store', 'items.product']);

        // Проверяем, существует ли Заказ поставщику в МойСклад
        if ($supplierOrder->moysklad_id && !$this->purchaseOrderService->checkExists($supplierOrder->moysklad_id)) {
            session()->put("sync_confirm_{$supplierOrder->id}", [
                'issue'          => 'order_missing',
                'suggested_name' => null,
            ]);
            return redirect()->route('supplier-orders.sync-confirm', $supplierOrder);
        }

        $result = $this->supplyService->createSupply($supplierOrder);

        if ($result['success']) {
            $supplierOrder->update([
                'supply_moysklad_id' => $result['supply_moysklad_id'],
                'status'             => SupplierOrder::STATUS_SENT,
                'sync_error'         => null,
            ]);
            return redirect()->route('supplier-orders.index')
                ->with('success', "Приёмка по поступлению №{$supplierOrder->number} создана в МойСклад.");
        }

        // Коллизия имени приёмки
        if ($result['code'] === 'duplicate_name') {
            $suggested = $this->nextSuffixName($supplierOrder->number);
            session()->put("sync_confirm_{$supplierOrder->id}", [
                'issue'          => 'duplicate_supply',
                'suggested_name' => $suggested,
            ]);
            return redirect()->route('supplier-orders.sync-confirm', $supplierOrder);
        }

        $supplierOrder->update(['sync_error' => $result['message']]);
        return redirect()->route('supplier-orders.index')
            ->with('danger', "Не удалось создать приёмку для №{$supplierOrder->number}: {$result['message']}");
    }

    /**
     * Показать страницу подтверждения при обнаруженной проблеме синхронизации.
     */
    public function syncConfirm(SupplierOrder $supplierOrder): \Illuminate\View\View|RedirectResponse
    {
        $confirm = session()->get("sync_confirm_{$supplierOrder->id}");
        if (!$confirm) {
            return redirect()->route('supplier-orders.index');
        }

        return view('supplier-orders.sync-confirm', [
            'order'   => $supplierOrder,
            'issue'   => $confirm['issue'],
            'suggested' => $confirm['suggested_name'],
        ]);
    }

    /**
     * Принудительная синхронизация после подтверждения пользователем.
     * mode=recreate  — пересоздать Заказ поставщику + Приёмку
     * mode=suffix_supply — создать Приёмку с суффиксным именем (Заказ существует)
     */
    public function forceSync(Request $request, SupplierOrder $supplierOrder): RedirectResponse
    {
        if ($supplierOrder->status !== SupplierOrder::STATUS_NEW) {
            return redirect()->route('supplier-orders.index')
                ->with('warning', 'Создать приёмку можно только для поступлений в статусе «Новый».');
        }

        $mode = $request->input('mode');
        session()->forget("sync_confirm_{$supplierOrder->id}");

        $supplierOrder->load(['counterparty', 'store', 'items.product']);

        if ($mode === 'recreate') {
            // Создаём Заказ поставщику заново (с автоподбором суффикса при коллизии)
            $poName   = $supplierOrder->number;
            $poResult = $this->purchaseOrderService->createPurchaseOrder($supplierOrder, $poName);

            if (!$poResult['success'] && $poResult['code'] === 'duplicate_name') {
                $poName   = $this->nextSuffixName($poName);
                $poResult = $this->purchaseOrderService->createPurchaseOrder($supplierOrder, $poName);
            }

            if (!$poResult['success']) {
                $supplierOrder->update(['sync_error' => $poResult['message']]);
                return redirect()->route('supplier-orders.index')
                    ->with('danger', "Не удалось создать Заказ поставщику в МойСклад: {$poResult['message']}");
            }

            // Сохраняем новый moysklad_id (и, возможно, изменившееся имя)
            $supplierOrder->update([
                'moysklad_id' => $poResult['moysklad_id'],
                'number'      => $poName,
                'sync_error'  => null,
            ]);
            $supplierOrder->refresh();

            // Создаём Приёмку (с тем же именем, что и заказ; с автоподбором суффикса при коллизии)
            $supplyName   = $poName;
            $supplyResult = $this->supplyService->createSupply($supplierOrder, $supplyName);

            if (!$supplyResult['success'] && $supplyResult['code'] === 'duplicate_name') {
                $supplyName   = $this->nextSuffixName($supplyName);
                $supplyResult = $this->supplyService->createSupply($supplierOrder, $supplyName);
            }

            if (!$supplyResult['success']) {
                $supplierOrder->update(['sync_error' => $supplyResult['message']]);
                return redirect()->route('supplier-orders.index')
                    ->with('danger', "Заказ поставщику создан, но не удалось создать Приёмку: {$supplyResult['message']}");
            }

            $supplierOrder->update([
                'supply_moysklad_id' => $supplyResult['supply_moysklad_id'],
                'number'             => $supplyName,
                'status'             => SupplierOrder::STATUS_SENT,
                'sync_error'         => null,
            ]);

            return redirect()->route('supplier-orders.index')
                ->with('success', "Заказ поставщику и Приёмка №{$supplierOrder->fresh()->number} созданы в МойСклад.");
        }

        if ($mode === 'suffix_supply') {
            $supplyName   = $request->input('suggested_name') ?: $this->nextSuffixName($supplierOrder->number);
            $supplyResult = $this->supplyService->createSupply($supplierOrder, $supplyName);

            if (!$supplyResult['success']) {
                $supplierOrder->update(['sync_error' => $supplyResult['message']]);
                return redirect()->route('supplier-orders.index')
                    ->with('danger', "Не удалось создать Приёмку с именем «{$supplyName}»: {$supplyResult['message']}");
            }

            $supplierOrder->update([
                'supply_moysklad_id' => $supplyResult['supply_moysklad_id'],
                'number'             => $supplyName,
                'status'             => SupplierOrder::STATUS_SENT,
                'sync_error'         => null,
            ]);

            return redirect()->route('supplier-orders.index')
                ->with('success', "Приёмка создана в МойСклад с именем «{$supplyName}». Номер поступления обновлён.");
        }

        return redirect()->route('supplier-orders.index')
            ->with('warning', 'Действие отменено.');
    }

    /**
     * Генерирует следующий суффиксный номер:
     * «26-15-ПРОГ-01» → «26-15-ПРОГ-01_01»
     * «26-15-ПРОГ-01_01» → «26-15-ПРОГ-01_02»
     */
    private function nextSuffixName(string $name): string
    {
        if (preg_match('/^(.+)_(\d+)$/', $name, $m)) {
            return $m[1] . '_' . str_pad((int)$m[2] + 1, 2, '0', STR_PAD_LEFT);
        }
        return $name . '_01';
    }

    /**
     * API: следующий номер заказа на текущей неделе.
     * Формат: ГГ-НН-ПРОГ-ПП (например: 26-15-ПРОГ-01)
     */
    public function nextOrderNumber(): \Illuminate\Http\JsonResponse
    {
        $year  = now()->format('y');
        $week  = now()->format('W');

        $count = SupplierOrder::whereBetween('created_at', [
            now()->startOfWeek(),
            now()->endOfWeek(),
        ])->count();

        $number = "{$year}-{$week}-ПРОГ-" . str_pad($count + 1, 2, '0', STR_PAD_LEFT);

        return response()->json(['number' => $number]);
    }
}
