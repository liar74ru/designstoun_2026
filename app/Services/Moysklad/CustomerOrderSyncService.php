<?php

namespace App\Services\Moysklad;

use App\Models\Counterparty;
use App\Models\Department;
use App\Models\Order;
use App\Models\OrderState;
use App\Models\Product;
use App\Services\OrderChangeService;
use App\Services\OrderProductionService;
use App\Support\OrderPriority;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CustomerOrderSyncService extends MoySkladBaseService
{
    public function __construct(
        private MoySkladService $moySkladService,
        private OrderStateSyncService $stateSync,
        private OrderProductionService $production,
        private OrderChangeService $changes,
    ) {
        parent::__construct();
    }

    /**
     * Подтянуть заявки из МойСклад со статусами из настроек.
     *
     * @return array{success: bool, count: int, message: string}
     */
    public function pullActive(): array
    {
        if (! $this->hasCredentials()) {
            return ['success' => false, 'count' => 0, 'message' => 'MOYSKLAD_TOKEN не установлен'];
        }

        // Справочник статусов освежаем перед выгрузкой: иначе новый статус,
        // заведённый в МойСклад, не попадёт в список, пока админ не нажмёт кнопку.
        $this->stateSync->sync();

        $stateIds = OrderState::enabled()->pluck('id')->all();
        if (empty($stateIds)) {
            return [
                'success' => false,
                'count'   => 0,
                'message' => 'Не выбрано ни одного статуса. Отметьте нужные в админке (Статусы заявок).',
            ];
        }

        try {
            $rows = $this->fetchOrders($stateIds);
        } catch (\Throwable $e) {
            Log::error('Ошибка получения customerorder из МойСклад', ['error' => $e->getMessage()]);
            return ['success' => false, 'count' => 0, 'message' => 'Ошибка API: ' . $e->getMessage()];
        }

        // Неполная выгрузка: удалять «выпавшие» заявки по ней нельзя — не трогаем ничего
        if ($rows === null) {
            return [
                'success' => false,
                'count'   => 0,
                'message' => 'Не удалось получить заявки из МойСклад. Локальные заявки не изменены.',
            ];
        }

        $count = 0;

        if (! empty($rows)) {
            $counterpartyMap   = $this->resolveCounterparties($rows);
            $productMap        = $this->resolveProducts($rows);
            $departmentNameMap = Department::pluck('id', 'name');
            $untrackedStateIds = OrderState::untracked()->pluck('id')->all();
            $existingOrders    = Order::whereIn('moysklad_id', array_column($rows, 'id'))
                ->get(['moysklad_id', 'state_moysklad_id', 'sync_hash'])
                ->keyBy('moysklad_id')
                ->map(fn (Order $o) => $o->only(['state_moysklad_id', 'sync_hash']))
                ->all();

            foreach ($rows as $row) {
                DB::transaction(function () use ($row, $counterpartyMap, $productMap, $departmentNameMap, $existingOrders, $untrackedStateIds) {
                    $this->upsertOrder($row, $counterpartyMap, $productMap, $departmentNameMap, $existingOrders, $untrackedStateIds);
                });
                $count++;
            }
        }

        $syncedIds = array_column($rows, 'id');
        $deleted = Order::whereNotIn('moysklad_id', $syncedIds)->delete();

        if ($count === 0 && $deleted === 0) {
            return ['success' => true, 'count' => 0, 'message' => 'Новых заявок не найдено.'];
        }

        $message = "Синхронизировано заявок: {$count}.";
        if ($deleted > 0) {
            $message .= " Удалено устаревших: {$deleted}.";
        }

        return [
            'success' => true,
            'count'   => $count,
            'message' => $message,
        ];
    }

    /**
     * Тянем все заявки с нужными state. МойСклад поддерживает фильтр через несколько state=
     * параметров, разделённых ';' внутри строки фильтра.
     *
     * С expand МойСклад отдаёт не больше 100 строк на страницу. Число заявок узнаём
     * лёгким запросом без expand (limit=1, ~0,7 с), затем все страницы запрашиваем разом
     * (Http::pool): время выгрузки — время одной страницы, а не сумма (~21 с → ~6 с).
     *
     * Любая неудачная страница — null, а не частичный список: по выгрузке pullActive()
     * удаляет выпавшие заявки, и неполный список стёр бы живые заявки с их настройками.
     *
     * @return array<int, array>|null
     */
    private function fetchOrders(array $stateIds): ?array
    {
        $stateFilter = implode(';', array_map(
            fn ($id) => 'state=' . $this->baseUrl . '/entity/customerorder/metadata/states/' . $id,
            $stateIds,
        ));

        $probe = $this->get('/entity/customerorder', ['limit' => 1, 'filter' => $stateFilter]);
        if (! $probe || ! isset($probe['meta']['size'])) {
            return null;
        }

        $limit    = 100;
        $requests = [];
        for ($offset = 0; $offset < $probe['meta']['size']; $offset += $limit) {
            $requests[$offset] = ['/entity/customerorder', [
                'limit'  => $limit,
                'offset' => $offset,
                'order'  => 'moment,desc',
                'expand' => 'positions.assortment,state,agent',
                'filter' => $stateFilter,
            ]];
        }

        $rows = [];
        foreach ($this->getMany($requests) as $page) {
            if (! $page || ! isset($page['rows'])) {
                return null;
            }
            $rows = array_merge($rows, $page['rows']);
        }

        return $rows;
    }

    /**
     * Сопоставить UUID контрагентов из заявок с локальной БД. Если хоть один отсутствует —
     * однократно гоняем массовую синхронизацию и пересобираем карту.
     *
     * @return array<string,string>  [moysklad_id => counterparties.id]
     */
    private function resolveCounterparties(array $rows): array
    {
        $agentIds = [];
        foreach ($rows as $row) {
            if ($id = $this->extractIdFromMeta($row['agent']['meta']['href'] ?? null)) {
                $agentIds[$id] = true;
            }
        }
        $agentIds = array_keys($agentIds);

        if (empty($agentIds)) {
            return [];
        }

        $map = Counterparty::whereIn('moysklad_id', $agentIds)
            ->pluck('id', 'moysklad_id')
            ->all();

        $missing = array_diff($agentIds, array_keys($map));
        if (! empty($missing)) {
            Log::info('CustomerOrderSync: контрагенты не найдены, запускаем syncCounterparties()', [
                'missing_count' => count($missing),
            ]);
            $this->moySkladService->syncCounterparties();

            $map = Counterparty::whereIn('moysklad_id', $agentIds)
                ->pluck('id', 'moysklad_id')
                ->all();

            $stillMissing = array_diff($agentIds, array_keys($map));
            if (! empty($stillMissing)) {
                Log::warning('CustomerOrderSync: после syncCounterparties часть контрагентов не найдена', [
                    'missing' => array_values($stillMissing),
                ]);
            }
        }

        return $map;
    }

    /**
     * Карта product.moysklad_id → product.id для всех позиций.
     *
     * @return array<string,int>
     */
    private function resolveProducts(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            foreach ($row['positions']['rows'] ?? [] as $pos) {
                if ($id = $this->extractIdFromMeta($pos['assortment']['meta']['href'] ?? null)) {
                    $ids[$id] = true;
                }
            }
        }

        if (empty($ids)) {
            return [];
        }

        return Product::whereIn('moysklad_id', array_keys($ids))
            ->pluck('id', 'moysklad_id')
            ->all();
    }

    private function upsertOrder(
        array $row,
        array $counterpartyMap,
        array $productMap,
        \Illuminate\Support\Collection $departmentNameMap,
        array $existingOrders,
        array $untrackedStateIds,
    ): bool {
        $agentMoyskladId = $this->extractIdFromMeta($row['agent']['meta']['href'] ?? null);
        $stateMoyskladId = $this->extractIdFromMeta($row['state']['meta']['href'] ?? null);

        $attributes = [
            'name'                => $row['name'] ?? '',
            'state_moysklad_id'   => $stateMoyskladId,
            'state_name'          => $row['state']['name'] ?? null,
            'counterparty_id'     => $agentMoyskladId ? ($counterpartyMap[$agentMoyskladId] ?? null) : null,
            'agent_name'          => $row['agent']['name'] ?? null,
            'moment'              => $row['moment'] ?? null,
            'delivery_planned_at' => $row['deliveryPlannedMoment'] ?? null,
            'attributes'          => $row['attributes'] ?? [],
        ];

        $items = [];
        foreach ($row['positions']['rows'] ?? [] as $pos) {
            $assortment = $pos['assortment'] ?? [];
            $productMoyskladId = $this->extractIdFromMeta($assortment['meta']['href'] ?? null);

            $items[] = [
                'product_id'          => $productMoyskladId ? ($productMap[$productMoyskladId] ?? null) : null,
                'product_moysklad_id' => $productMoyskladId,
                'product_name'        => $assortment['name'] ?? null,
                'quantity'            => $pos['quantity'] ?? 0,
                'shipped'             => $pos['shipped'] ?? 0,
                'uom_name'            => $assortment['uom']['name'] ?? null,
            ];
        }

        $matchedIds = [];
        foreach ($row['attributes'] ?? [] as $attr) {
            $type  = $attr['type']  ?? null;
            $value = $attr['value'] ?? null;
            if ($type === 'boolean' && $value === true) {
                $name = $attr['name'] ?? null;
                if ($name !== null && $departmentNameMap->has($name)) {
                    $matchedIds[] = $departmentNameMap->get($name);
                }
            }
        }
        $departmentIds = array_values(array_unique($matchedIds));

        // Отпечаток ровно того, что пишем, — а не поле updated МойСклад: shipped позиций
        // меняется отгрузками без правки заявки, а товар, контрагент или отдел могут
        // появиться в программе позже. Совпал — заявка та же, пропускаем.
        $hash = md5(json_encode([$attributes, $items, $departmentIds]));

        // Статус меняют и прямо в МойСклад, минуя программу, — сравниваем до upsert,
        // иначе вход в производство останется незамеченным.
        $existing = $existingOrders[$row['id']] ?? null;
        if ($existing && $existing['sync_hash'] === $hash) {
            return false;
        }
        $previousStateId = $existing['state_moysklad_id'] ?? null;

        $order = Order::updateOrCreate(
            ['moysklad_id' => $row['id']],
            $attributes + ['sync_hash' => $hash],
        );

        // Ручной ключ не трогаем: мастер переставил заявку осознанно, и смена срока
        // в МойСклад не должна молча вернуть её на авто-место.
        if (! $order->priority_manual) {
            $order->update([
                'priority_key' => OrderPriority::autoKey($order->delivery_planned_at, $order->moment),
            ]);
        }

        $before = $existing ? $this->quantitiesByProduct($order->items()->get()->toArray()) : null;

        $order->items()->delete();
        foreach ($items as $item) {
            $order->items()->create($item);
        }

        $order->departments()->sync($departmentIds);

        if ($before !== null) {
            $after = $this->quantitiesByProduct($items);

            // Правки в проекте до запуска и после отгрузки — не изменение для мастера.
            if (! in_array($previousStateId, $untrackedStateIds, true)
                && ! in_array($stateMoyskladId, $untrackedStateIds, true)) {
                $this->changes->record($order, $before, $after);
            }

            // Окно открыто, а позиция новая — снимка по ней нет. До syncPeriod: при входе
            // в производство снимок по всему составу снимет он сам.
            $added = collect($items)
                ->filter(fn ($item) => $item['product_id'] && ! isset($before[$item['product_moysklad_id']]))
                ->pluck('product_id')->unique()->values()->all();
            $this->production->freezeAdded($order, $added);
        }

        $this->changes->rememberStateBeforeChange($order, $previousStateId, $stateMoyskladId);

        if ($previousStateId !== $stateMoyskladId) {
            // Позиции только что пересозданы — снимок остатка ляжет по актуальному составу.
            $this->production->syncPeriod($order->load('items.product'), $stateMoyskladId);
        }

        return true;
    }

    /**
     * Перевести заявку в другой статус.
     *
     * Пишем сначала в МойСклад и только при успехе обновляем локальные поля:
     * иначе в программе окажется статус, которого в МойСклад нет.
     *
     * @return array{success: bool, code: string, message: string}
     */
    public function updateState(Order $order, OrderState $state): array
    {
        $result = ['success' => false, 'code' => '', 'message' => ''];

        if (! $this->hasCredentials()) {
            $result['code']    = 'no_credentials';
            $result['message'] = 'MOYSKLAD_TOKEN не установлен';

            return $result;
        }

        try {
            $payload = [
                'state' => [
                    'meta' => [
                        'href'      => $this->baseUrl . '/entity/customerorder/metadata/states/' . $state->id,
                        'type'      => 'state',
                        'mediaType' => 'application/json',
                    ],
                ],
            ];

            $response = $this->put('/entity/customerorder/' . $order->moysklad_id, $payload);

            if (! $response->successful()) {
                $errors = $response->json()['errors'] ?? [];
                $result['code']    = 'api_error';
                $result['message'] = 'Ошибка МойСклад: ' . ($errors[0]['error'] ?? $errors[0]['title'] ?? 'Неизвестная ошибка');

                Log::error('Ошибка смены статуса заявки в МойСклад', [
                    'order_id' => $order->id,
                    'state'    => $state->name,
                    'status'   => $response->status(),
                    'response' => $response->json(),
                ]);

                return $result;
            }

            $order->update([
                'state_moysklad_id' => $state->id,
                'state_name'        => $state->name,
            ]);

            $result['success'] = true;
            $result['message'] = "Статус заявки изменён на «{$state->name}».";

            Log::info('Статус заявки изменён', ['order_id' => $order->id, 'state' => $state->name]);
        } catch (\Throwable $e) {
            $result['code']    = 'exception';
            $result['message'] = 'Ошибка: ' . $e->getMessage();

            Log::error('Исключение при смене статуса заявки', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Задать заявке дату готовности — поле «Планируемая дата отгрузки» (deliveryPlannedMoment).
     *
     * Пишем без сдвига часового пояса (не через MoyskladMoment): синхронизация кладёт это поле
     * в БД как есть, и со сдвигом дата после следующей выгрузки съехала бы на 2 часа, а полночь —
     * на предыдущий день. Время прежней даты сохраняем — меняется только день.
     *
     * @return array{success: bool, code: string, message: string}
     */
    public function updateDeliveryDate(Order $order, Carbon $date): array
    {
        $result = ['success' => false, 'code' => '', 'message' => ''];

        if (! $this->hasCredentials()) {
            $result['code']    = 'no_credentials';
            $result['message'] = 'MOYSKLAD_TOKEN не установлен';

            return $result;
        }

        $current = $order->delivery_planned_at;
        $moment  = $date->copy()->setTime(
            $current?->hour ?? 0,
            $current?->minute ?? 0,
            $current?->second ?? 0,
        );

        try {
            $response = $this->put('/entity/customerorder/' . $order->moysklad_id, [
                'deliveryPlannedMoment' => $moment->format('Y-m-d H:i:s'),
            ]);

            if (! $response->successful()) {
                $errors = $response->json()['errors'] ?? [];
                $result['code']    = 'api_error';
                $result['message'] = 'Ошибка МойСклад: ' . ($errors[0]['error'] ?? $errors[0]['title'] ?? 'Неизвестная ошибка');

                Log::error('Ошибка смены даты готовности заявки в МойСклад', [
                    'order_id' => $order->id,
                    'date'     => $moment->format('Y-m-d H:i:s'),
                    'status'   => $response->status(),
                    'response' => $response->json(),
                ]);

                return $result;
            }

            $order->update(['delivery_planned_at' => $moment]);

            // Как при синхронизации: ручное место в очереди не трогаем, авто-ключ пересчитываем
            // сразу — иначе заявка встанет на новое место только после следующей выгрузки.
            if (! $order->priority_manual) {
                $order->update([
                    'priority_key' => OrderPriority::autoKey($order->delivery_planned_at, $order->moment),
                ]);
            }

            $result['success'] = true;
            $result['message'] = 'Дата готовности заявки ' . $order->name . ' — ' . $moment->format('d.m.Y') . '.';

            Log::info('Дата готовности заявки изменена', ['order_id' => $order->id, 'date' => $moment->format('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            $result['code']    = 'exception';
            $result['message'] = 'Ошибка: ' . $e->getMessage();

            Log::error('Исключение при смене даты готовности заявки', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Назначить заявке отделы.
     *
     * Отдел в МойСклад — булев доп. реквизит с именем отдела (так их разбирает
     * upsertOrder). Пишем туда, а не только локально: иначе ближайшая синхронизация
     * перезапишет выбор. Снятые отделы сбрасываем в false.
     *
     * @param  array<int, int>  $departmentIds
     */
    public function updateDepartments(Order $order, array $departmentIds): array
    {
        $result = ['success' => false, 'code' => '', 'message' => ''];

        if (! $this->hasCredentials()) {
            $result['code']    = 'no_credentials';
            $result['message'] = 'MOYSKLAD_TOKEN не установлен';

            return $result;
        }

        $departmentIds = array_values(array_unique(array_map('intval', $departmentIds)));

        try {
            $metadata = $this->get('/entity/customerorder/metadata/attributes');
            if ($metadata === null) {
                $result['code']    = 'api_error';
                $result['message'] = 'Не удалось получить реквизиты заказа из МойСклад';

                return $result;
            }

            $attributeMeta = [];
            foreach ($metadata['rows'] ?? [] as $attr) {
                if (($attr['type'] ?? null) === 'boolean' && isset($attr['name'], $attr['meta'])) {
                    $attributeMeta[$attr['name']] = $attr['meta'];
                }
            }

            $departments = Department::orderBy('name')->get(['id', 'name']);

            $missing = $departments
                ->whereIn('id', $departmentIds)
                ->reject(fn ($d) => isset($attributeMeta[$d->name]))
                ->pluck('name');

            if ($missing->isNotEmpty()) {
                $result['code']    = 'no_attribute';
                $result['message'] = 'В МойСклад нет реквизита-флажка для отдела: «'
                    . $missing->implode('», «') . '»';

                return $result;
            }

            $attributes = $departments
                ->filter(fn ($d) => isset($attributeMeta[$d->name]))
                ->map(fn ($d) => [
                    'meta'  => $attributeMeta[$d->name],
                    'value' => in_array($d->id, $departmentIds, true),
                ])
                ->values()
                ->all();

            $response = $this->put('/entity/customerorder/' . $order->moysklad_id, ['attributes' => $attributes]);

            if (! $response->successful()) {
                $errors = $response->json()['errors'] ?? [];
                $result['code']    = 'api_error';
                $result['message'] = 'Ошибка МойСклад: ' . ($errors[0]['error'] ?? 'Неизвестная ошибка');

                Log::error('Ошибка записи отделов заявки в МойСклад', [
                    'order_id' => $order->id,
                    'status'   => $response->status(),
                    'response' => $response->json(),
                ]);

                return $result;
            }

            $order->departments()->sync($departmentIds);

            $result['success'] = true;
            $result['message'] = $departmentIds
                ? 'Отделы заявки «' . $order->name . '» сохранены.'
                : 'У заявки «' . $order->name . '» сняты все отделы.';

            Log::info('Отделы заявки изменены', ['order_id' => $order->id, 'departments' => $departmentIds]);
        } catch (\Throwable $e) {
            $result['code']    = 'exception';
            $result['message'] = 'Ошибка: ' . $e->getMessage();

            Log::error('Исключение при записи отделов заявки', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Количество по товару: позиции одного товара складываются.
     *
     * @return array<string, array{name: ?string, quantity: float}>
     */
    private function quantitiesByProduct(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $key = $item['product_moysklad_id'] ?? null;
            if ($key === null) {
                continue;
            }
            $result[$key] = [
                'name'     => $item['product_name'] ?? null,
                'quantity' => ($result[$key]['quantity'] ?? 0.0) + (float) $item['quantity'],
            ];
        }

        return $result;
    }

    private function extractIdFromMeta(?string $href): ?string
    {
        if (! $href) {
            return null;
        }
        if (preg_match('/([a-f0-9\-]{36})/i', $href, $m)) {
            return $m[1];
        }
        return null;
    }
}
