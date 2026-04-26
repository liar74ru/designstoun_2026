<?php

namespace App\Services;

use App\Models\Counterparty;
use App\Models\Store;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderItem;
use App\Models\Worker;
use App\Support\DocumentNaming;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SupplierOrderService
{
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

    public function getRecentOrders(int $limit = 20): Collection
    {
        return SupplierOrder::with(['counterparty', 'store', 'items.product'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function getCopySource(int $id): ?SupplierOrder
    {
        return SupplierOrder::with(['counterparty', 'items.product'])->find($id);
    }

    public function create(array $data, bool $isAdmin): SupplierOrder
    {
        $createdAt = ($isAdmin && !empty($data['manual_created_at']))
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

        return $order;
    }

    public function update(SupplierOrder $order, array $data, bool $isAdmin): SupplierOrder
    {
        $createdAt = ($isAdmin && !empty($data['manual_created_at']))
            ? Carbon::parse($data['manual_created_at'])
            : $order->created_at;

        DB::transaction(function () use ($data, $createdAt, $order) {
            $order->update([
                'number'          => $data['number'],
                'store_id'        => $data['store_id'],
                'counterparty_id' => $data['counterparty_id'],
                'receiver_id'     => $data['receiver_id'] ?? null,
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
