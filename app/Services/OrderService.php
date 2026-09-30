<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Order;
use App\Models\OrderState;
use App\Models\Product;
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
        private InternalOrderService $internal,
    ) {
    }

    public function getIndexData(Request $request): array
    {
        $accessible = $request->user()?->accessibleDepartmentIds();
        $statuses   = $this->statuses();
        $defaults   = $this->defaultStatuses();

        $orders = $this->indexQuery($request)
            ->with(['items.product.stocks', 'departments', 'counterparty', 'customerDepartment', 'parent', 'internalChildren:id,uuid,parent_uuid,name', 'positionSettings'])
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
            'kindOptions'        => [Order::KIND_CUSTOMER => 'Заявки покупателей', Order::KIND_INTERNAL => 'Внутренние заказы'],
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
                        $w->whereHas('departments', fn ($d) => $d->whereIn('departments.id', $ids ?: [-1]))
                            ->orWhereIn('customer_department_id', $ids ?: [-1]);
                        if ($withNone) {
                            $w->orWhereDoesntHave('departments');
                        }
                    });
                }),
                AllowedFilter::callback('kind', fn ($q, $v) =>
                    $q->where('kind', $v === Order::KIND_INTERNAL ? Order::KIND_INTERNAL : Order::KIND_CUSTOMER)),
                // Не фильтр выборки, а режим отображения позиций — см. getIndexData().
                AllowedFilter::callback('show_hidden', fn ($q) => $q),
            ])
            // Заявки без отдела видны всем — их отдел назначают из списка — но по умолчанию
            // скрыты: показываются только при отмеченном «Без отдела».
            // Внутренний заказ видит и заказчик — это его исходящий заказ.
            ->when($accessible !== null, fn ($q) =>
                $q->where(fn ($w) => $w
                    ->whereHas('departments', fn ($d) =>
                        $d->whereIn('departments.id', $accessible ?: [-1]))
                    ->orWhereDoesntHave('departments')
                    ->orWhereIn('customer_department_id', $accessible ?: [-1])))
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
     * Заказ по uuid с проверкой доступа по отделу. Локальные id живут только до ближайшей
     * синхронизации (CustomerOrderSyncService пересоздаёт выпавшие), поэтому ссылки строятся
     * по uuid: у заявки покупателя он равен moysklad_id, у внутреннего заказа — свой.
     * В списке чужие заказы отфильтрованы, но по прямой ссылке были бы видны — отсюда 403.
     * Заявка без отдела доступна всем: отдел ей назначают из списка. Внутренний заказ
     * доступен и исполнителю, и заказчику.
     *
     * @param  array<int, string>  $with
     */
    public function findForUser(Request $request, string $uuid, array $with = []): Order
    {
        $order = Order::query()
            ->with(array_merge(['departments'], $with))
            ->where('uuid', $uuid)
            ->firstOrFail();

        $accessible = $request->user()?->accessibleDepartmentIds();
        $participants = $order->departments->pluck('id')
            ->push($order->customer_department_id)
            ->filter()->all();

        if ($accessible !== null && $participants !== [] && empty(array_intersect($accessible, $participants))) {
            abort(403);
        }

        return $order;
    }

    /**
     * Данные карточки заявки.
     */
    public function getShowData(Request $request, string $moyskladId): array
    {
        $user  = $request->user();
        $order = $this->findForUser($request, $moyskladId, [
            'items.product.stocks',
            'counterparty',
            'customerDepartment',
            'parent',
            'positionSettings.user.worker',
        ]);

        $defaultStoreId = $this->effectiveStoreId($order, $user);

        return [
            'order'          => $order,
            'attributes'     => $this->visibleAttributes($order),
            'rows'           => $this->orderRows($order, $user),
            // Внутренние заказы: у заявки — полуфабрикаты других отделов под неё;
            // у самого внутреннего — ссылка на основание и права на состав.
            'internalOrders'      => $order->isInternal() ? [] : $this->internalOrders($order, $user),
            'canCreateInternal'   => ! $order->isInternal() && $this->internalCustomerDepartments($order, $user)->isNotEmpty(),
            'internalParent'      => $order->isInternal() ? $order->parent : null,
            'canManageInternal'   => $this->internal->canManage($user, $order),
            'canAcknowledge'      => $this->internal->canAcknowledge($user, $order),
            // Полный список складов нужен модалке: мастер выбирает, откуда собирает позицию.
            'stores'         => Store::where('archived', false)->orderBy('name')->get(),
            'defaultStoreId' => $defaultStoreId,
            'orderStates'    => $this->enabledStates(),
            'departments'    => $this->assignableDepartments($request->user()),
            'hideDepartments' => $this->hideableDepartments($order, $request->user()),
            'changedStateId' => OrderState::changedId(),
            // Куда «Принято» вернёт заявку из статуса «Изменено»
            'returnState'    => $this->changes->isInChangedState($order) ? $this->changes->returnState($order) : null,
        ];
    }

    /**
     * Данные страницы создания или правки внутреннего заказа.
     * Создание — от заявки-основания (uuid основания), правка — сам внутренний заказ.
     */
    public function getInternalFormData(Request $request, string $uuid, bool $edit): array
    {
        $user  = $request->user();
        $order = $this->findForUser($request, $uuid, ['items.product']);

        if ($edit) {
            abort_unless($order->isInternal(), 404);
            abort_unless($this->internal->canManage($user, $order), 403);
            $parent = $order->parent()->with(['items.product.stocks', 'departments', 'positionSettings'])->first();
        } else {
            abort_if($order->isInternal(), 404);
            $parent = $order->load(['items.product.stocks', 'positionSettings']);
        }

        $customerDepartments = $edit ? collect() : $this->internalCustomerDepartments($parent, $user);
        abort_if(! $edit && $customerDepartments->isEmpty(), 403);

        $parentRows = $parent ? $this->orderRows($parent, $user) : collect();

        // Сколько ещё нужно по позиции: нехватка, а без чисел — остаток к отгрузке.
        $need = $parentRows->filter(fn ($row) => $row['product'])
            ->mapWithKeys(fn ($row) => [$row['product']->id => (float) ($row['short'] ?? $row['left'])])
            ->all();

        $items = $request->old('items') ?? ($edit
            ? $order->items->map(fn ($item) => [
                'product_id'        => $item->product_id,
                'quantity'          => (float) $item->quantity,
            ])->all()
            : [['product_id' => '', 'quantity' => '']]);

        $products = Product::whereIn('id', array_filter(array_column($items, 'product_id')))->get()->keyBy('id');

        return [
            'order'               => $edit ? $order : null,
            'parent'              => $parent,
            'parentRows'          => $parentRows,
            'customerDepartments' => $customerDepartments,
            'executorDepartments' => Department::orderBy('name')->get(),
            'states'              => $this->enabledStates(),
            'defaultStateId'      => $parent ? $this->internal->defaultState($parent)?->id : null,
            'suggestions'         => $this->internal->presetSuggestions(
                $need,
                $parent ? $parent->departments->pluck('id')->all() : [],
            ),
            'items'               => collect($items)->map(fn ($item) => $item + [
                'label' => $products[$item['product_id'] ?? 0]?->name ?? '',
            ])->values()->all(),
        ];
    }

    /**
     * От каких отделов пользователь может заказать полуфабрикат под заявку: её отделы
     * (у заявки без отдела — любые), к которым он может отнести запись.
     *
     * @return SupportCollection<int, Department>
     */
    public function internalCustomerDepartments(Order $parent, ?User $user): SupportCollection
    {
        $accessible = $user?->accessibleDepartmentIds();
        $departments = $parent->departments->isNotEmpty()
            ? $parent->departments->sortBy('name')->values()
            : Department::orderBy('name')->get();

        return $accessible === null
            ? $departments
            : $departments->whereIn('id', $accessible)->values();
    }

    /** Строки позиций заказа — с долей в общем объёме товара. */
    private function orderRows(Order $order, ?User $user): SupportCollection
    {
        return $this->positions->rows(
            $order,
            $this->effectiveStoreId($order, $user),
            $this->production->producedForOrder($order),
            $this->allocate(collect([$order]), $user)[$order->id] ?? [],
        );
    }

    /**
     * Внутренние заказы под заявку — для блока в её карточке: номер, исполнитель, статус
     * и готовность каждой строки. Числа — те же, что в карточке самого внутреннего заказа
     * (своя доля в общем пуле, изготовленное за окно).
     *
     * @return array<int, array<string, mixed>>
     */
    private function internalOrders(Order $order, ?User $user): array
    {
        $children = $order->internalChildren()
            ->with(['items.product.stocks', 'departments', 'positionSettings'])
            ->prioritized()
            ->get();

        if ($children->isEmpty()) {
            return [];
        }

        $produced   = $this->production->producedForOrders($children);
        $allocation = $this->allocate($children, $user);
        $accessible = $user?->accessibleDepartmentIds();

        return $children->map(function (Order $child) use ($produced, $allocation, $accessible, $user) {
            $participants = $child->departments->pluck('id')->push($child->customer_department_id)->filter()->all();

            $rows = $this->positions->rows(
                $child,
                $this->effectiveStoreId($child, $user),
                $produced[$child->id] ?? [],
                $allocation[$child->id] ?? [],
            );

            return [
                'order'    => $child,
                'canOpen'  => $accessible === null || array_intersect($accessible, $participants) !== [],
                'executor' => $child->departments->pluck('name')->implode(', '),
                'rows'     => $rows->map(fn ($row) => [
                    'name'    => $row['name'],
                    'uom'     => $row['item']->uom_name,
                    'ordered' => $row['ordered'],
                    'total'   => $row['totalQty'],
                    'isReady' => $row['isReady'],
                    'color'   => $row['color'],
                ])->all(),
            ];
        })->all();
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
