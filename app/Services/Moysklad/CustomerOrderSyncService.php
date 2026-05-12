<?php

namespace App\Services\Moysklad;

use App\Models\Counterparty;
use App\Models\Department;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CustomerOrderSyncService extends MoySkladBaseService
{
    public function __construct(
        private OrderService $orderService,
        private MoySkladService $moySkladService,
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

        $statusNames = $this->orderService->statuses();
        if (empty($statusNames)) {
            return [
                'success' => false,
                'count'   => 0,
                'message' => 'Список статусов пуст. Настройте его в админке (Статусы заявок).',
            ];
        }

        $stateIds = $this->resolveStateIds($statusNames);
        if (empty($stateIds)) {
            return [
                'success' => false,
                'count'   => 0,
                'message' => 'В МойСклад не найдено статусов: ' . implode(', ', $statusNames),
            ];
        }

        try {
            $rows = $this->fetchOrders($stateIds);
        } catch (\Throwable $e) {
            Log::error('Ошибка получения customerorder из МойСклад', ['error' => $e->getMessage()]);
            return ['success' => false, 'count' => 0, 'message' => 'Ошибка API: ' . $e->getMessage()];
        }

        if (empty($rows)) {
            return ['success' => true, 'count' => 0, 'message' => 'Новых заявок не найдено.'];
        }

        $counterpartyMap   = $this->resolveCounterparties($rows);
        $productMap        = $this->resolveProducts($rows);
        $departmentNameMap = Department::pluck('id', 'name');

        $count = 0;
        foreach ($rows as $row) {
            DB::transaction(function () use ($row, $counterpartyMap, $productMap, $departmentNameMap, &$count) {
                $this->upsertOrder($row, $counterpartyMap, $productMap, $departmentNameMap);
                $count++;
            });
        }

        return [
            'success' => true,
            'count'   => $count,
            'message' => "Синхронизировано заявок: {$count}.",
        ];
    }

    /**
     * Получить state.id из metadata customerorder по именам статусов.
     */
    private function resolveStateIds(array $names): array
    {
        $meta = $this->get('/entity/customerorder/metadata');
        $states = $meta['states'] ?? [];

        $ids = [];
        foreach ($states as $state) {
            if (in_array($state['name'] ?? null, $names, true)) {
                $ids[] = $state['id'] ?? null;
            }
        }

        return array_values(array_filter($ids));
    }

    /**
     * Тянем все заявки с нужными state. МойСклад поддерживает фильтр через несколько state=
     * параметров, разделённых ';' внутри строки фильтра.
     */
    private function fetchOrders(array $stateIds): array
    {
        $stateFilter = implode(';', array_map(
            fn ($id) => 'state=' . $this->baseUrl . '/entity/customerorder/metadata/states/' . $id,
            $stateIds,
        ));

        $rows   = [];
        $offset = 0;
        $limit  = 100;

        do {
            $data = $this->get('/entity/customerorder', [
                'limit'  => $limit,
                'offset' => $offset,
                'order'  => 'moment,desc',
                'expand' => 'positions.assortment,state,agent',
                'filter' => $stateFilter,
            ]);

            if (! $data || ! isset($data['rows'])) {
                break;
            }

            $rows  = array_merge($rows, $data['rows']);
            $total = $data['meta']['size'] ?? count($rows);
            $offset += $limit;
        } while ($offset < $total);

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
    ): void {
        $agentMoyskladId = $this->extractIdFromMeta($row['agent']['meta']['href'] ?? null);
        $stateMoyskladId = $this->extractIdFromMeta($row['state']['meta']['href'] ?? null);

        $order = Order::updateOrCreate(
            ['moysklad_id' => $row['id']],
            [
                'name'              => $row['name'] ?? '',
                'state_moysklad_id' => $stateMoyskladId,
                'state_name'        => $row['state']['name'] ?? null,
                'counterparty_id'   => $agentMoyskladId ? ($counterpartyMap[$agentMoyskladId] ?? null) : null,
                'agent_name'        => $row['agent']['name'] ?? null,
                'moment'            => $row['moment'] ?? null,
                'attributes'        => $row['attributes'] ?? [],
            ],
        );

        $order->items()->delete();
        foreach ($row['positions']['rows'] ?? [] as $pos) {
            $assortment = $pos['assortment'] ?? [];
            $productMoyskladId = $this->extractIdFromMeta($assortment['meta']['href'] ?? null);

            $order->items()->create([
                'product_id'          => $productMoyskladId ? ($productMap[$productMoyskladId] ?? null) : null,
                'product_moysklad_id' => $productMoyskladId,
                'product_name'        => $assortment['name'] ?? null,
                'quantity'            => $pos['quantity'] ?? 0,
                'shipped'             => $pos['shipped'] ?? 0,
                'uom_name'            => $assortment['uom']['name'] ?? null,
            ]);
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
        $order->departments()->sync(array_values(array_unique($matchedIds)));
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
