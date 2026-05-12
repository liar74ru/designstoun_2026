<?php

namespace App\Services;

use App\Http\Controllers\Admin\OrderStatusSettingController;
use App\Models\Department;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class OrderService
{
    public function getIndexData(Request $request): array
    {
        $accessible = $request->user()?->accessibleDepartmentIds();
        $statuses   = $this->statuses();

        $orders = QueryBuilder::for(Order::class)
            ->with(['items.product.stocks.store', 'departments', 'counterparty'])
            ->allowedFilters([
                AllowedFilter::callback('status', fn ($q, $v) =>
                    $q->whereIn('state_name', (array) $v)),
                AllowedFilter::callback('department_id', fn ($q, $v) =>
                    $q->whereHas('departments', fn ($d) =>
                        $d->whereIn('departments.id', (array) $v))),
            ])
            ->when($accessible !== null, fn ($q) =>
                $q->whereHas('departments', fn ($d) =>
                    $d->whereIn('departments.id', $accessible ?: [-1])))
            ->orderByDesc('moment')
            ->paginate(20)
            ->withQueryString();

        $productionStoreId = $request->user()?->worker?->department?->default_production_store_id;

        return [
            'orders'             => $orders,
            'statusOptions'      => array_combine($statuses, $statuses),
            'statusDefaults'     => $statuses,
            'filterDepartments'  => Department::orderBy('name')->get(),
            'departmentDefaults' => $accessible ?? [],
            'productionStoreId'  => $productionStoreId,
        ];
    }

    public function statuses(): array
    {
        $raw = Setting::get(OrderStatusSettingController::SETTING_KEY, '[]');

        return json_decode($raw, true) ?: [];
    }
}
