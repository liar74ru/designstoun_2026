<?php

namespace App\Services;

use App\Models\Counterparty;
use App\Models\Department;
use App\Models\Store;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderItem;
use App\Models\Worker;
use App\Support\DocumentNaming;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class SupplierOrderService
{
    public function getIndexData(Request $request): array
    {
        $accessible = $request->user()?->accessibleDepartmentIds();

        $orders = QueryBuilder::for(SupplierOrder::class)
            ->allowedFilters([
                AllowedFilter::callback('department_id', fn($q, $v) =>
                    $q->whereIn('supplier_orders.department_id', (array) $v)),
                AllowedFilter::exact('status'),
            ])
            ->with(['counterparty', 'store', 'receiver', 'items.product', 'department'])
            ->when($request->filled('date_from'), fn($q) =>
                $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn($q) =>
                $q->whereDate('created_at', '<=', $request->date_to))
            ->when(
                $accessible !== null && !array_key_exists('department_id', $request->input('filter', [])),
                fn($q) => $q->whereIn('supplier_orders.department_id', $accessible ?: [-1])
            )
            ->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        $filterDepartments  = Department::orderBy('name')->get();
        $departmentDefaults = $accessible ?? [];

        return compact('orders', 'filterDepartments', 'departmentDefaults');
    }

    public function getFormOptions(): array
    {
        $stores = Store::where('archived', false)->orderBy('name')->get();
        $counterparties = Counterparty::orderBy('name')->get();
        $receivers = Worker::where(function ($q) {
            foreach (['Директор', 'Администратор', 'Мастер', 'Кладовщик'] as $pos) {
                $q->orWhereJsonContains('positions', $pos);
            }
        })->orderBy('name')->get();

        return compact('stores', 'counterparties', 'receivers');
    }

    public function getRecentOrders(int $limit = 20, ?Request $request = null): Collection
    {
        $accessible = $request?->user()?->accessibleDepartmentIds();

        return SupplierOrder::with(['counterparty', 'store', 'items.product'])
            ->when($accessible !== null,
                fn($q) => $q->whereIn('department_id', $accessible ?: [-1]))
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function getCopySource(string $id): ?SupplierOrder
    {
        return SupplierOrder::with(['counterparty', 'items.product'])->find($id);
    }

    public function create(array $data, bool $isAdmin): SupplierOrder
    {
        $createdAt = ($isAdmin && !empty($data['manual_created_at']))
            ? Carbon::parse($data['manual_created_at'])
            : now();

        $departmentId = $data['department_id']
            ?? (isset($data['receiver_id']) ? Worker::find($data['receiver_id'])?->department_id : null);

        DB::transaction(function () use ($data, $createdAt, $departmentId, &$order) {
            $order = SupplierOrder::create([
                'number'          => $data['number'],
                'store_id'        => $data['store_id'],
                'counterparty_id' => $data['counterparty_id'],
                'receiver_id'     => $data['receiver_id'] ?? null,
                'department_id'   => $departmentId,
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

        return $order;
    }

    public function update(SupplierOrder $order, array $data, bool $isAdmin): SupplierOrder
    {
        $createdAt = ($isAdmin && !empty($data['manual_created_at']))
            ? Carbon::parse($data['manual_created_at'])
            : $order->created_at;

        $departmentId = $data['department_id']
            ?? (isset($data['receiver_id']) ? Worker::find($data['receiver_id'])?->department_id : $order->department_id);

        DB::transaction(function () use ($data, $createdAt, $departmentId, $order) {
            $order->update([
                'number'          => $data['number'],
                'store_id'        => $data['store_id'],
                'counterparty_id' => $data['counterparty_id'],
                'receiver_id'     => $data['receiver_id'] ?? null,
                'department_id'   => $departmentId,
                'note'            => $data['note'] ?? null,
                'created_at'      => $createdAt,
            ]);

            $order->items()->delete();
            foreach ($data['products'] as $item) {
                SupplierOrderItem::create([
                    'supplier_order_id' => $order->id,
                    'product_id'        => $item['product_id'],
                    'quantity'          => $item['quantity'],
                ]);
            }
        });

        return $order;
    }

    public function delete(SupplierOrder $order): void
    {
        $order->items()->delete();
        $order->delete();
    }

    public function nextOrderNumber(): string
    {
        $count = SupplierOrder::whereBetween('created_at', [
            now()->startOfWeek(),
            now()->endOfWeek(),
        ])->count();

        return DocumentNaming::weeklyName('ПРОГ', $count + 1);
    }
}
