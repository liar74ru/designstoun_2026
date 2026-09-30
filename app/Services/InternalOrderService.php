<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderState;
use App\Models\Product;
use App\Models\User;
use App\Models\WorkshopItem;
use App\Models\WorkshopPreset;
use App\Services\Moysklad\CustomerOrderSyncService;
use App\Support\DocumentNaming;
use App\Support\OrderPriority;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Внутренний заказ: отдел-заказчик заказывает отделу-исполнителю полуфабрикаты под заявку
 * покупателя целиком — связь только с заявкой, не с её позициями. Живёт в той же таблице orders (kind = internal), поэтому очередь,
 * окно производства и раздача остатка работают для него так же, как для заявки.
 *
 * В МойСклад не выгружается: статус, срок и исполнители меняются локально
 * (ветки isInternal() в CustomerOrderSyncService).
 */
class InternalOrderService
{
    public const NAME_PREFIX = 'ВЗ';

    public function __construct(
        private OrderProductionService $production,
        private OrderChangeService $changes,
        private CustomerOrderSyncService $sync,
    ) {
    }

    /**
     * @param  array{customer_department_id: int, executor_department_id: int, state_id: string,
     *               delivery_planned_at?: ?string, items: array<int, array>}  $data
     */
    public function create(Order $parent, array $data, User $user): Order
    {
        $state = OrderState::enabled()->findOrFail($data['state_id']);

        $order = DB::transaction(function () use ($parent, $data, $user, $state) {
            $moment   = now();
            $delivery = ! empty($data['delivery_planned_at'])
                ? Carbon::createFromFormat('Y-m-d', $data['delivery_planned_at'])->startOfDay()
                : $parent->delivery_planned_at;

            $order = Order::create([
                'kind'                   => Order::KIND_INTERNAL,
                'customer_department_id' => $data['customer_department_id'],
                'parent_uuid'            => $parent->uuid,
                'parent_order_name'      => $parent->name,
                'created_by_user_id'     => $user->id,
                'name'                   => $this->nextName(),
                'state_moysklad_id'      => $state->id,
                'state_name'             => $state->name,
                'moment'                 => $moment,
                'delivery_planned_at'    => $delivery,
                'is_urgent'              => $parent->is_urgent,
                // Без ключа заказ встал бы первым в очереди (default 0) и забрал весь пул.
                'priority_key'           => OrderPriority::autoKey($delivery, $moment),
                'attributes'             => [],
            ]);

            foreach ($this->itemRows($data['items']) as $item) {
                $order->items()->create($item);
            }

            $order->departments()->sync([(int) $data['executor_department_id']]);

            return $order;
        });

        $this->production->syncPeriod($order->load('items.product'), $state->id);

        return $order;
    }

    /**
     * Правка заказа. Изменилось количество, а заказ уже в работе — как у заявки из МойСклад:
     * мастер видит «было → стало», а заказ встаёт на паузу «Изменено» до «Принято».
     *
     * @param  array{executor_department_id: int, delivery_planned_at?: ?string, items: array<int, array>}  $data
     */
    public function update(Order $order, array $data): void
    {
        $before = $this->changes->quantitiesByProduct($order->items()->get()->toArray());
        $items  = $this->itemRows($data['items']);

        DB::transaction(function () use ($order, $data, $items) {
            $order->items()->delete();
            foreach ($items as $item) {
                $order->items()->create($item);
            }

            $order->departments()->sync([(int) $data['executor_department_id']]);
        });

        if (! empty($data['delivery_planned_at'])) {
            $this->sync->updateDeliveryDate($order, Carbon::createFromFormat('Y-m-d', $data['delivery_planned_at']));
        }

        $after = $this->changes->quantitiesByProduct($items);

        // Правки до запуска и после выдачи — не изменение для мастера (правило заявок).
        $tracked = $order->state_moysklad_id !== null
            && ! OrderState::untracked()->whereKey($order->state_moysklad_id)->exists();

        if ($tracked) {
            $this->changes->record($order, $before, $after);

            if ($this->quantitiesDiffer($before, $after)) {
                $this->pauseAsChanged($order);
            }
        }

        $added = collect($items)
            ->filter(fn ($item) => ! isset($before[$item['product_moysklad_id']]))
            ->pluck('product_id')->unique()->values()->all();
        $this->production->freezeAdded($order->load('items.product'), $added);
    }

    public function delete(Order $order): void
    {
        $order->delete();
    }

    /** Состав и удаление — у заказчика: исполнитель не должен молча урезать чужой заказ. */
    public function canManage(User $user, Order $order): bool
    {
        if (! $order->isInternal()) {
            return false;
        }

        $accessible = $user->accessibleDepartmentIds();

        return $accessible === null || in_array((int) $order->customer_department_id, $accessible, true);
    }

    /** «Принято» у внутреннего заказа — исполнитель: иначе заказчик принял бы свою же правку. */
    public function canAcknowledge(User $user, Order $order): bool
    {
        if (! $order->isInternal()) {
            return true;
        }

        $accessible = $user->accessibleDepartmentIds();

        return $accessible === null
            || array_intersect($accessible, $order->departments->pluck('id')->all()) !== [];
    }

    /** Статус нового заказа: как у основания, если он используется и это не пауза «Изменено». */
    public function defaultState(Order $parent): ?OrderState
    {
        $state = $parent->state_moysklad_id
            ? OrderState::enabled()->where('is_changed', false)->find($parent->state_moysklad_id)
            : null;

        return $state
            ?? OrderState::enabled()->production()->orderBy('position')->first()
            ?? OrderState::enabled()->orderBy('position')->first();
    }

    /**
     * Номер «ГГ-НН-ВЗ-ПП»: max за неделю + 1, а не count — после удаления номера не повторяются.
     */
    public function nextName(): string
    {
        $prefix = DocumentNaming::weekPrefix(self::NAME_PREFIX);

        $sequence = DocumentNaming::nextSequence(
            Order::internal()->where('name', 'like', $prefix . '%')->pluck('name'),
            $prefix,
        );

        return DocumentNaming::weeklyName(self::NAME_PREFIX, $sequence);
    }

    /**
     * Подсказка состава из шаблонов цеха: шаблон отдела-заказчика, где позиция основания —
     * продукт, даёт сырьё по норме. Норма шаблона задана на весь его состав, поэтому
     * сырьё на единицу = сырьё ÷ количество продукта в шаблоне.
     *
     * @param  array<int, float>  $needByProduct  [product_id позиции основания => сколько ещё нужно]
     * @param  array<int, int>  $departmentIds  отделы, чьи шаблоны смотрим
     * @return array<int, array<int, array{preset: string, department_id: int, items: array<int, array{product_id: int, label: string, quantity: float}>}>>
     */
    public function presetSuggestions(array $needByProduct, array $departmentIds): array
    {
        if ($needByProduct === [] || $departmentIds === []) {
            return [];
        }

        $presets = WorkshopPreset::query()
            ->whereIn('department_id', $departmentIds)
            ->whereHas('items', fn ($q) => $q->where('role', WorkshopItem::ROLE_PRODUCT)
                ->whereIn('product_id', array_keys($needByProduct)))
            ->with('items.product')
            ->orderBy('name')
            ->get();

        $result = [];
        foreach ($presets as $preset) {
            $raws = $preset->items->where('role', WorkshopItem::ROLE_RAW)->filter(fn ($i) => $i->product);

            foreach ($preset->items->where('role', WorkshopItem::ROLE_PRODUCT) as $productItem) {
                $need   = $needByProduct[$productItem->product_id] ?? null;
                $perSet = (float) $productItem->quantity;

                if ($need === null || $perSet <= 0 || $raws->isEmpty()) {
                    continue;
                }

                $result[$productItem->product_id][] = [
                    'preset'        => $preset->name,
                    'department_id' => $preset->department_id,
                    'items'         => $raws->map(fn ($raw) => [
                        'product_id' => $raw->product_id,
                        'label'      => $raw->product->name . ($raw->product->sku ? ' (' . $raw->product->sku . ')' : ''),
                        'quantity'   => round((float) $raw->quantity * $need / $perSet, 3),
                    ])->values()->all(),
                ];
            }
        }

        return $result;
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float|string}>  $input
     * @return array<int, array<string, mixed>>
     */
    private function itemRows(array $input): array
    {
        $products = Product::whereIn('id', array_column($input, 'product_id'))->get()->keyBy('id');

        return collect($input)->map(function ($row) use ($products) {
            $product = $products[(int) $row['product_id']];

            return [
                'product_id'          => $product->id,
                // Ключ «было → стало» в OrderChangeService
                'product_moysklad_id' => $product->moysklad_id ?? 'local-' . $product->id,
                'product_name'        => $product->name,
                'quantity'            => (float) $row['quantity'],
                'shipped'             => 0,
                'uom_name'            => $product->uom,
            ];
        })->values()->all();
    }

    /**
     * @param  array<string, array{quantity: float}>  $before
     * @param  array<string, array{quantity: float}>  $after
     */
    private function quantitiesDiffer(array $before, array $after): bool
    {
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            if ((float) ($before[$key]['quantity'] ?? 0) !== (float) ($after[$key]['quantity'] ?? 0)) {
                return true;
            }
        }

        return false;
    }

    /** Перевести в «Изменено», запомнив, откуда: «Принято» вернёт туда. Статуса нет — только плашка. */
    private function pauseAsChanged(Order $order): void
    {
        $changedId = OrderState::changedId();

        if ($changedId === null || $order->state_moysklad_id === $changedId) {
            return;
        }

        $this->changes->rememberStateBeforeChange($order, $order->state_moysklad_id, $changedId);
        $this->sync->updateState($order, OrderState::findOrFail($changedId));
        $this->production->syncPeriod($order, $changedId);
    }
}
