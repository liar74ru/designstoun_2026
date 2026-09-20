<?php

namespace App\Services;

use App\Http\Controllers\Admin\OrderStatusSettingController;
use App\Models\Department;
use App\Models\Order;
use App\Models\Setting;
use Carbon\Carbon;
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
            'switchDepartments'  => Department::query()
                ->when($accessible !== null, fn ($q) => $q->whereIn('id', $accessible ?: [-1]))
                ->whereHas('orders')
                ->orderBy('name')
                ->get(),
            'productionStoreId'  => $productionStoreId,
        ];
    }

    /**
     * Данные карточки заявки. Заявка ищется по moysklad_id — локальные id живут
     * только до ближайшей синхронизации (CustomerOrderSyncService чистит выпавшие).
     */
    public function getShowData(Request $request, string $moyskladId): array
    {
        $order = Order::query()
            ->with(['items.product.stocks.store', 'departments', 'counterparty'])
            ->where('moysklad_id', $moyskladId)
            ->firstOrFail();

        $accessible = $request->user()?->accessibleDepartmentIds();
        if ($accessible !== null && empty(array_intersect($accessible, $order->departments->pluck('id')->all()))) {
            abort(403);
        }

        return [
            'order'             => $order,
            'attributes'        => $this->visibleAttributes($order),
            'productionStoreId' => $request->user()?->worker?->department?->default_production_store_id,
            'backUrl'           => url()->previous(route('orders.index')),
        ];
    }

    /**
     * Доп. реквизиты МойСклад для карточки: булевы отброшены — они уже разобраны в отделы.
     *
     * @return array<string, string>
     */
    private function visibleAttributes(Order $order): array
    {
        $result = [];

        foreach ($order->attributes ?? [] as $attr) {
            $name = $attr['name'] ?? null;
            $type = $attr['type'] ?? null;
            $value = $attr['value'] ?? null;

            if (! $name || $type === 'boolean') {
                continue;
            }

            if (is_array($value)) {
                $value = $value['name'] ?? null;
            } elseif ($type === 'time' && $value) {
                $value = Carbon::parse($value)->format('d.m.Y');
            }

            if ($value === null || $value === '') {
                continue;
            }

            $result[$name] = (string) $value;
        }

        return $result;
    }

    public function statuses(): array
    {
        $raw = Setting::get(OrderStatusSettingController::SETTING_KEY, '[]');

        return json_decode($raw, true) ?: [];
    }
}
