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
    /** Значение пункта «Без отдела» в фильтре filter[department_id][]. */
    public const NO_DEPARTMENT = 'none';

    public function __construct(
        private OrderPositionService $positions,
        private OrderProductionService $production,
    ) {
    }

    public function getIndexData(Request $request): array
    {
        $accessible = $request->user()?->accessibleDepartmentIds();
        $statuses   = $this->statuses();
        $defaults   = $this->defaultStatuses();

        $orders = QueryBuilder::for(Order::class)
            ->with(['items.product.stocks', 'departments', 'counterparty', 'positionSettings'])
            ->allowedFilters([
                AllowedFilter::callback('status', fn ($q, $v) =>
                    $q->whereIn('state_name', (array) $v)),
                AllowedFilter::callback('department_id', function ($q, $v) {
                    $values   = (array) $v;
                    $ids      = array_values(array_filter($values, 'is_numeric'));
                    $withNone = in_array(self::NO_DEPARTMENT, $values, true);

                    $q->where(function ($w) use ($ids, $withNone) {
                        $w->whereHas('departments', fn ($d) => $d->whereIn('departments.id', $ids ?: [-1]));
                        if ($withNone) {
                            $w->orWhereDoesntHave('departments');
                        }
                    });
                }),
            ])
            // Заявки без отдела видны всем — их отдел назначают из списка — но по умолчанию
            // скрыты: показываются только при отмеченном «Без отдела».
            ->when($accessible !== null, fn ($q) =>
                $q->where(fn ($w) => $w
                    ->whereHas('departments', fn ($d) =>
                        $d->whereIn('departments.id', $accessible ?: [-1]))
                    ->orWhereDoesntHave('departments')))
            ->when(! $request->has('filter.department_id'), fn ($q) =>
                $q->has('departments'))
            // Фильтр статусов не пришёл — показываем набор, отмеченный админом «по умолчанию».
            // Проверяем наличие ключа: пустой filter[status] форма не присылает вовсе.
            ->when(! $request->has('filter.status') && $defaults !== [], fn ($q) =>
                $q->whereIn('state_name', $defaults))
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

        $departments = Department::orderBy('name')->get();

        return [
            'orders'             => $orders,
            'statusOptions'      => array_combine($statuses, $statuses),
            'statusDefaults'     => $defaults,
            'filterDepartments'  => $departments,
            'noDepartmentOption' => self::NO_DEPARTMENT,
            'assignDepartments'  => $departments,
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
     * были бы видны — отсюда 403. Заявка без отдела доступна всем: отдел ей назначают
     * из списка.
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
        if ($accessible !== null && $order->departments->isNotEmpty() && empty(array_intersect($accessible, $order->departments->pluck('id')->all()))) {
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
            'departments'    => Department::orderBy('name')->get(),
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

    /** Имена используемых статусов — варианты в фильтре списка заявок. */
    public function statuses(): array
    {
        return OrderState::enabled()->orderBy('position')->pluck('name')->all();
    }

    /**
     * Статусы, показываемые в списке при заходе без фильтра.
     *
     * Пересекаем с используемыми: забытая галочка на выключенном статусе не должна
     * сужать выдачу. Ничего не отмечено — показываем все используемые, как было до
     * появления настройки, а не пустой список.
     *
     * @return array<int, string>
     */
    public function defaultStatuses(): array
    {
        $defaults = OrderState::enabled()->defaultFilter()->orderBy('position')->pluck('name')->all();

        return $defaults ?: $this->statuses();
    }
}
