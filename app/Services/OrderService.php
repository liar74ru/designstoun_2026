<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Order;
use App\Models\OrderState;
use App\Models\Store;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class OrderService
{
    public function __construct(
        private OrderPositionService $positions,
        private OrderProductionService $production,
    ) {
    }

    public function getIndexData(Request $request): array
    {
        $accessible = $request->user()?->accessibleDepartmentIds();
        $statuses   = $this->statuses();

        $orders = QueryBuilder::for(Order::class)
            ->with(['items.product.stocks', 'departments', 'counterparty', 'positionSettings'])
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

        // Изготовленное — двумя выборками на всю страницу, а не по заявке.
        $produced = $this->production->producedForOrders($orders->getCollection());

        $rowsByOrder = $orders->getCollection()
            ->mapWithKeys(fn (Order $order) => [
                $order->id => $this->positions->rows(
                    $order,
                    $this->effectiveStoreId($order, $request->user()),
                    $produced[$order->id] ?? [],
                ),
            ])
            ->all();

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
            'rowsByOrder'        => $rowsByOrder,
            'orderStates'        => $this->enabledStates(),
        ];
    }

    /** Используемые статусы — для переключателя статуса заявки. */
    public function enabledStates(): Collection
    {
        return OrderState::enabled()->orderBy('position')->get();
    }

    /**
     * Склад, остаток по которому показывается и уточняется в заявке.
     *
     * Определяется от заявки, а не от смотрящего: поправка хранится вместе со store_id,
     * и склад «по пользователю» развёл бы админа и мастера по разным строкам поправок.
     * Цепочка по образцу StoneReception::effectiveDepartmentId().
     */
    public function effectiveStoreId(Order $order, ?User $user): ?string
    {
        $fromOrder = $order->departments
            ->sortBy('id')
            ->firstWhere(fn ($d) => ! empty($d->default_production_store_id));

        return $fromOrder?->default_production_store_id
            ?? $user?->worker?->department?->default_production_store_id
            ?? Store::getDefault()?->id;
    }

    /**
     * Заявка по moysklad_id с проверкой доступа по отделу. Локальные id живут только
     * до ближайшей синхронизации (CustomerOrderSyncService чистит выпавшие), поэтому
     * ищем по moysklad_id. В списке чужие заявки отфильтрованы, но по прямой ссылке
     * были бы видны — отсюда 403.
     *
     * @param  array<int, string>  $with
     */
    public function findForUser(Request $request, string $moyskladId, array $with = []): Order
    {
        $order = Order::query()
            ->with(array_merge(['departments'], $with))
            ->where('moysklad_id', $moyskladId)
            ->firstOrFail();

        $accessible = $request->user()?->accessibleDepartmentIds();
        if ($accessible !== null && empty(array_intersect($accessible, $order->departments->pluck('id')->all()))) {
            abort(403);
        }

        return $order;
    }

    /**
     * Данные карточки заявки.
     */
    public function getShowData(Request $request, string $moyskladId): array
    {
        $order = $this->findForUser($request, $moyskladId, [
            'items.product.stocks',
            'counterparty',
            'positionSettings.user.worker',
        ]);

        $defaultStoreId = $this->effectiveStoreId($order, $request->user());

        return [
            'order'          => $order,
            'attributes'     => $this->visibleAttributes($order),
            'rows'           => $this->positions->rows(
                $order,
                $defaultStoreId,
                $this->production->producedForOrder($order),
            ),
            // Полный список складов нужен модалке: мастер выбирает, откуда собирает позицию.
            'stores'         => Store::where('archived', false)->orderBy('name')->get(),
            'defaultStoreId' => $defaultStoreId,
            'orderStates'    => $this->enabledStates(),
            'backUrl'        => url()->previous(route('orders.index')),
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

    /** Имена используемых статусов — для фильтра в списке заявок. */
    public function statuses(): array
    {
        return OrderState::enabled()->orderBy('position')->pluck('name')->all();
    }
}
