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
use Illuminate\Support\Collection as SupportCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class OrderService
{
    /** Значение пункта «Без отдела» в фильтре filter[department_id][]. */
    public const NO_DEPARTMENT = 'none';

    public function __construct(
        private OrderPositionService $positions,
        private OrderProductionService $production,
        private OrderChangeService $changes,
    ) {
    }

    public function getIndexData(Request $request): array
    {
        $accessible = $request->user()?->accessibleDepartmentIds();
        $statuses   = $this->statuses();
        $defaults   = $this->defaultStatuses();

        $orders = $this->indexQuery($request)
            ->with(['items.product.stocks', 'departments', 'counterparty', 'positionSettings'])
            ->listOrdered()
            ->paginate(20)
            ->withQueryString();

        // Изготовленное — двумя выборками на всю страницу, а не по заявке.
        $produced   = $this->production->producedForOrders($orders->getCollection());
        $allocation = $this->allocate($orders->getCollection(), $request->user());

        $rowsByOrder = $orders->getCollection()
            ->mapWithKeys(fn (Order $order) => [
                $order->id => $this->positions->rows(
                    $order,
                    $this->effectiveStoreId($order, $request->user()),
                    $produced[$order->id] ?? [],
                    $allocation[$order->id] ?? [],
                ),
            ])
            ->all();

        // Позиции, скрытые для отделов смотрящего. Режем уже посчитанные строки:
        // доли остатка и изготовленного от отображения не зависят.
        $viewDepartmentIds  = $this->viewDepartmentIds($request);
        $showHidden         = $request->boolean('filter.show_hidden');
        $hiddenCountByOrder = [];

        foreach ($orders->getCollection() as $order) {
            $orderDepartmentIds = $order->departments->pluck('id')->all();

            $rows = $rowsByOrder[$order->id]->map(fn (array $row) => $row + [
                'hidden' => $this->positions->isHiddenFor($row['hiddenFor'], $viewDepartmentIds, $orderDepartmentIds),
            ]);

            $hiddenCountByOrder[$order->id] = $rows->where('hidden', true)->count();
            $rowsByOrder[$order->id] = $showHidden ? $rows : $rows->reject(fn ($row) => $row['hidden'])->values();
        }

        $departments = Department::orderBy('name')->get();

        return [
            'orders'             => $orders,
            'statusOptions'      => array_combine($statuses, $statuses),
            'statusDefaults'     => $defaults,
            'filterDepartments'  => $departments,
            'noDepartmentOption' => self::NO_DEPARTMENT,
            'assignDepartments'  => $this->assignableDepartments($request->user()),
            'departmentDefaults' => $accessible ?? [],
            'switchDepartments'  => Department::query()
                ->when($accessible !== null, fn ($q) => $q->whereIn('id', $accessible ?: [-1]))
                ->whereHas('orders')
                ->orderBy('name')
                ->get(),
            'rowsByOrder'        => $rowsByOrder,
            'hiddenCountByOrder' => $hiddenCountByOrder,
            'showHidden'         => $showHidden,
            'orderStates'        => $this->enabledStates(),
            'changedStateId'     => OrderState::changedId(),
        ];
    }

    /**
     * Заявки списка с фильтрами и ограничением по отделам — без порядка и пагинации.
     * Тот же запрос ищет соседа при ручном перемещении: «выше/ниже» считается
     * в том списке, который видит пользователь.
     */
    public function indexQuery(Request $request): QueryBuilder
    {
        $accessible = $request->user()?->accessibleDepartmentIds();
        $defaults   = $this->defaultStatuses();

        return QueryBuilder::for(Order::class)
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
                // Не фильтр выборки, а режим отображения позиций — см. getIndexData().
                AllowedFilter::callback('show_hidden', fn ($q) => $q),
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
                $q->whereIn('state_name', $defaults));
    }

    /**
     * Отделы, «чьими глазами» смотрят на список: от них зависит, какие позиции скрыты.
     * Отдел выбран фильтром или переключателем — он; иначе отделы пользователя.
     * Админ без выбранного отдела видит все позиции.
     *
     * @return array<int, int>
     */
    public function viewDepartmentIds(Request $request): array
    {
        $accessible = $request->user()?->accessibleDepartmentIds();

        if (! $request->has('filter.department_id')) {
            return $accessible ?? [];
        }

        $ids = array_map('intval', array_filter((array) $request->input('filter.department_id'), 'is_numeric'));

        return array_values($accessible === null ? $ids : array_intersect($ids, $accessible));
    }

    /**
     * Отделы, которые пользователь может ставить заявке и снимать с неё: админ — любые,
     * остальные — только свои.
     *
     * @return Collection<int, Department>
     */
    public function assignableDepartments(?User $user): Collection
    {
        $accessible = $user?->accessibleDepartmentIds();

        return Department::query()
            ->when($accessible !== null, fn ($q) => $q->whereIn('id', $accessible ?: [-1]))
            ->orderBy('name')
            ->get();
    }

    /**
     * Итоговые отделы заявки после правки: выбранные пользователем + отделы заявки
     * вне его зоны — их не-админ в модалке не видит и снять не может.
     *
     * @param  array<int, int|string>  $selected
     * @return array<int, int>
     */
    public function resolveDepartments(Order $order, array $selected, ?User $user): array
    {
        $selected   = array_map('intval', $selected);
        $accessible = $user?->accessibleDepartmentIds();

        if ($accessible === null) {
            return $selected;
        }

        $foreign = array_diff($order->departments->pluck('id')->map(fn ($id) => (int) $id)->all(), $accessible);

        return array_values(array_unique(array_merge($selected, $foreign)));
    }

    /**
     * Отделы заявки, для которых пользователь может скрывать позиции: админ — любой,
     * остальные — только свои.
     *
     * @return Collection<int, Department>
     */
    public function hideableDepartments(Order $order, ?User $user): Collection
    {
        $accessible = $user?->accessibleDepartmentIds();

        return $order->departments
            ->when($accessible !== null, fn ($c) => $c->whereIn('id', $accessible))
            ->sortBy('name')
            ->values();
    }

    /**
     * Доли заявок в общем объёме товара — по очереди приоритета.
     *
     * @param  SupportCollection<int, Order>  $orders
     * @return array<int, array<int, array<string, mixed>>>  [order_id => [product_id => доля]]
     */
    public function allocate(SupportCollection $orders, ?User $user): array
    {
        return $this->production->allocate(
            $orders,
            fn (Order $order) => $this->effectiveStoreId($order, $user),
        );
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
                $this->allocate(collect([$order]), $request->user())[$order->id] ?? [],
            ),
            // Полный список складов нужен модалке: мастер выбирает, откуда собирает позицию.
            'stores'         => Store::where('archived', false)->orderBy('name')->get(),
            'defaultStoreId' => $defaultStoreId,
            'orderStates'    => $this->enabledStates(),
            'departments'    => $this->assignableDepartments($request->user()),
            'hideDepartments' => $this->hideableDepartments($order, $request->user()),
            'changedStateId' => OrderState::changedId(),
            // Куда «Принято» вернёт заявку из статуса «Изменено»
            'returnState'    => $this->changes->isInChangedState($order) ? $this->changes->returnState($order) : null,
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
