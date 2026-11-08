<?php

namespace App\Services\Moysklad;

use App\Models\Setting;
use App\Models\StoneReception;
use App\Models\Workshop;
use App\Services\Moysklad\Concerns\HandlesProcessingSync;
use App\Support\DocumentNaming;
use App\Support\MoyskladMoment;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Общая база синхронизации производственного документа (приёмка, цех) с техоперацией МойСклад.
 *
 * Здесь — только то, что зависит от МойСклад и порядка синхронизации
 * (docs/moysklad-sync-pattern.md): поиск по externalCode, POST с повтором при занятом
 * имени, PUT, статус «в работе», итог для задачи очереди. Состав документа — склады,
 * продукты, материалы, processingSum, примечание — строит подкласс: он зависит от модели
 * и у приёмки и цеха разный.
 *
 * @template TDocument of StoneReception|Workshop
 */
abstract class ProcessingSyncService extends MoySkladBaseService
{
    use HandlesProcessingSync;

    public function __construct(
        protected StockSyncService $stockSyncService,
    ) {
        parent::__construct();
    }

    /** Тип документа — для externalCode и логов: 'reception', 'workshop'. */
    abstract protected function documentType(): string;

    /** Префикс имени техоперации: 'ТО', 'ЦЕХ'. */
    abstract protected function namePrefix(): string;

    /**
     * Примечание техоперации.
     *
     * @param  TDocument  $document
     */
    abstract protected function describe(Model $document): string;

    /**
     * Создать техоперацию документа. Подкласс зовёт свой публичный метод создания —
     * через него идут и тесты с частичными моками.
     *
     * @param  TDocument  $document
     * @return array ['success', 'processing_id', 'processing_name', 'code', 'message']
     */
    abstract protected function createDocument(Model $document, ?string $customName, ?string $description): array;

    /**
     * Обновить существующую техоперацию документа.
     *
     * @param  TDocument  $document
     * @return array ['success', 'code', 'message']
     */
    abstract protected function updateDocument(Model $document, ?string $description): array;

    /**
     * moysklad_id товаров, остатки которых двигает документ.
     *
     * @param  TDocument  $document
     * @return iterable<?string>
     */
    abstract protected function affectedMoyskladIds(Model $document): iterable;

    /** Связи, нужные для примечания и позиций. */
    protected function relationsForSync(): array
    {
        return ['items.product'];
    }

    /**
     * Причина, по которой документ отправить нельзя; null — можно.
     *
     * @param  TDocument  $document
     */
    protected function syncPrecheck(Model $document): ?string
    {
        return null;
    }

    /**
     * Синхронизировать документ: создать техоперацию (первая синхронизация) или обновить.
     *
     * @param  TDocument  $document
     * @param  array  $alsoRefresh  moysklad_id товаров «до правки» — их остатки тоже перечитываются
     * @return array ['success', 'message', 'retryable', 'created']
     */
    public function sync(Model $document, ?string $customName = null, array $alsoRefresh = []): array
    {
        $this->transientFailure = false;
        $context = [$this->documentType() . '_id' => $document->id];

        $blocked = $this->syncPrecheck($document);
        if ($blocked !== null) {
            // Молчать нельзя: документ остался бы с пустым moysklad_sync_status,
            // а бейдж «Не синхр» в реестре рисуется только при непустом значении.
            Log::warning($this->logPrefix('sync') . ': ' . $blocked, $context);
            $document->markSyncError($blocked);

            return $this->syncOutcome(false, $blocked);
        }

        try {
            $document->loadMissing($this->relationsForSync());
            $description = $this->describe($document);

            if (!$document->hasMoySkladProcessing()
                && !$this->adoptExistingProcessing($document, $this->externalCode($document))) {
                $message = 'МойСклад не ответил: не удалось проверить, создана ли техоперация';
                $document->markSyncError($message);

                return $this->syncOutcome(false, $message);
            }

            if (!$document->hasMoySkladProcessing()) {
                $result = $this->createDocument($document, $customName, $description ?: null);

                if ($result['success']) {
                    $document->markSynced($result['processing_id'], $result['processing_name']);
                    $this->refreshAffectedStocks($document, $alsoRefresh);
                } else {
                    $document->markSyncError($result['message']);
                    Log::warning($this->logPrefix('sync') . ': не удалось создать техоперацию', $context + [
                        'message' => $result['message'],
                    ]);
                }

                return $this->syncOutcome($result['success'], $result['message'], $result['success']);
            }

            $result = $this->updateDocument($document, $description ?: null);

            if ($result['success']) {
                $document->markSynced($document->moysklad_processing_id);
                $this->refreshAffectedStocks($document, $alsoRefresh);
            } else {
                $document->markSyncError($result['message']);
                Log::warning($this->logPrefix('sync') . ': не удалось обновить техоперацию', $context + [
                    'processing_id' => $document->moysklad_processing_id,
                    'message'       => $result['message'],
                ]);
            }

            return $this->syncOutcome($result['success'], $result['message']);
        } catch (\Exception $e) {
            Log::error($this->logPrefix('sync') . ': исключение', $context + ['error' => $e->getMessage()]);
            $document->markSyncError('Ошибка: ' . $e->getMessage());

            return $this->syncOutcome(false, 'Ошибка: ' . $e->getMessage());
        }
    }

    /**
     * Задача очереди: синхронизировать документ и привести статус техоперации к его статусу.
     *
     * @param  TDocument  $document
     * @return array ['success', 'message', 'retryable', 'created']
     */
    public function push(Model $document, ?string $customName = null, array $alsoRefresh = []): array
    {
        return $this->applyDocumentState($document, $this->sync($document, $customName, $alsoRefresh));
    }

    /**
     * Создать техоперацию: организация, состав документа от подкласса, имя по неделе,
     * externalCode, момент, примечание, статус «в работе»; POST с повтором при занятом имени.
     *
     * $buildPayload зовётся внутри try: позиция, которая не может войти в документ
     * (нет moysklad_id, не прочиталась мета), — ошибка документа, POST не уходит.
     *
     * @param  TDocument  $document
     * @param  callable(): array  $buildPayload  productsStore, materialsStore, processingSum, products, materials, quantity
     * @return array ['success', 'processing_id', 'processing_name', 'code', 'message']
     */
    protected function createProcessing(Model $document, ?string $customName, ?string $description, callable $buildPayload): array
    {
        $result = [
            'success'         => false,
            'processing_id'   => null,
            'processing_name' => null,
            'code'            => '',
            'message'         => '',
        ];
        $context = [$this->documentType() . '_id' => $document->id];

        try {
            if (!$this->hasCredentials()) {
                throw new \Exception('MoySklad токен не установлен');
            }

            $organizationMeta = $this->getOrganizationMeta();
            if (!$organizationMeta) {
                throw new \Exception('Не удалось получить данные организации');
            }

            $processingData = ['organization' => ['meta' => $organizationMeta]] + $buildPayload();

            $date = $document->created_at ?? now();
            $name = $customName ?? $this->weeklyName($document, $date);

            $processingData += [
                'externalCode' => $this->externalCode($document),
                'moment'       => MoyskladMoment::format($date),
            ];

            if ($description !== null) {
                $processingData['description'] = $description;
            }

            $inWorkName = Setting::get('MOYSKLAD_IN_WORK_STATE', '');
            if ($inWorkName) {
                $inWorkHref = $this->getProcessingStateHref($inWorkName);
                if ($inWorkHref) {
                    $processingData['state'] = [
                        'meta' => ['href' => $inWorkHref, 'type' => 'state', 'mediaType' => 'application/json'],
                    ];
                }
            }

            $response = null;
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $processingData['name'] = $name;
                $response = $this->post('/entity/processing', $processingData);

                if ($response->successful()) {
                    break;
                }

                $errors = $response->json()['errors'] ?? [];
                if (!DocumentNaming::isDuplicateName($errors)) {
                    break;
                }

                $name = DocumentNaming::nextSuffix($name);
            }

            if (!$response->successful()) {
                $result['code']    = 'api_error';
                $result['message'] = 'Ошибка МойСклад: ' . $this->extractApiError($response->json());
                Log::error($this->logPrefix('createProcessing') . ': ошибка API', $context + [
                    'status'   => $response->status(),
                    'response' => $response->json(),
                ]);

                return $result;
            }

            $data = $response->json();
            $result['success']         = true;
            $result['processing_id']   = $data['id'] ?? null;
            $result['processing_name'] = $data['name'] ?? $name;
            $result['message']         = 'Техоперация создана: ' . ($data['name'] ?? $name);

            Log::info($this->logPrefix('createProcessing') . ': успешно', $context + [
                'processing_id'   => $result['processing_id'],
                'processing_name' => $result['processing_name'],
            ]);

            return $result;

        } catch (\Exception $e) {
            $result['code']    = 'exception';
            $result['message'] = 'Ошибка: ' . $e->getMessage();
            Log::error($this->logPrefix('createProcessing') . ': исключение', $context + ['error' => $e->getMessage()]);

            return $result;
        }
    }

    /**
     * Обновить техоперацию: состав документа от подкласса, примечание, PUT.
     *
     * @param  callable(): array  $buildPayload  склады, processingSum, products, materials, quantity
     * @return array ['success', 'code', 'message']
     */
    protected function updateProcessing(string $processingId, ?string $description, callable $buildPayload): array
    {
        $result = ['success' => false, 'code' => '', 'message' => ''];

        try {
            if (!$this->hasCredentials()) {
                throw new \Exception('MoySklad токен не установлен');
            }

            $payload = $buildPayload();

            if ($description !== null) {
                $payload['description'] = $description;
            }

            $response = $this->put('/entity/processing/' . $processingId, $payload);

            if (!$response->successful()) {
                $result['code']    = 'api_error';
                $result['message'] = 'Ошибка МойСклад: ' . $this->extractApiError($response->json());
                Log::error($this->logPrefix('updateProcessing') . ': ошибка API', [
                    'processing_id' => $processingId,
                    'status'        => $response->status(),
                    'response'      => $response->json(),
                ]);

                return $result;
            }

            $result['success'] = true;
            $result['message'] = 'Техоперация обновлена';
            Log::info($this->logPrefix('updateProcessing') . ': успешно', ['processing_id' => $processingId]);

            return $result;

        } catch (\Exception $e) {
            $result['code']    = 'exception';
            $result['message'] = 'Ошибка: ' . $e->getMessage();
            Log::error($this->logPrefix('updateProcessing') . ': исключение', [
                'processing_id' => $processingId,
                'error'         => $e->getMessage(),
            ]);

            return $result;
        }
    }

    /**
     * Сгруппировать позиции по moysklad_id товара, позиции с нулевым итогом отбросить.
     *
     * @return array [moysklad_id => ['quantity' => float, 'assortment' => ['meta' => ...]]]
     *
     * @throws \Exception позиция не может войти в документ
     */
    protected function groupPositions(iterable $items): array
    {
        $grouped = [];
        foreach ($items as $item) {
            $moyskladId = $this->positionMoyskladId($item->product);
            if (isset($grouped[$moyskladId])) {
                $grouped[$moyskladId]['quantity'] += (float) $item->quantity;
            } else {
                $grouped[$moyskladId] = [
                    'quantity'   => (float) $item->quantity,
                    'assortment' => ['meta' => $this->positionMeta($item->product)],
                ];
            }
        }

        return array_filter($grouped, fn ($p) => $p['quantity'] > 0);
    }

    /**
     * Позиции для PUT: у уже существующих в техоперации — их id, чтобы МойСклад
     * правил позицию, а не создавал новую.
     *
     * @param  array  $grouped  результат groupPositions()
     * @param  string  $section  products | materials
     */
    protected function withExistingIds(array $grouped, string $processingId, string $section): array
    {
        $existingIds = $this->fetchExistingPositionIds($processingId, $section);

        $positions = [];
        foreach ($grouped as $moyskladId => $position) {
            if (isset($existingIds[$moyskladId])) {
                $position['id'] = $existingIds[$moyskladId];
            }
            $positions[] = $position;
        }

        return $positions;
    }

    /**
     * Подтянуть из МойСклад актуальные остатки товаров документа и $alsoRefresh.
     * Вызывается только после успешной синхронизации; ошибки логирует
     * StockSyncService::refreshProducts().
     *
     * @param  TDocument  $document
     */
    protected function refreshAffectedStocks(Model $document, array $alsoRefresh = []): void
    {
        $this->stockSyncService->refreshProducts(
            collect($alsoRefresh)->merge($this->affectedMoyskladIds($document))
        );
    }

    /** Получить техоперацию из МойСклад. */
    public function getProcessing(string $processingId)
    {
        if (!$this->hasCredentials()) {
            return null;
        }

        return $this->get('/entity/processing/' . $processingId);
    }

    /** @param  TDocument  $document */
    private function externalCode(Model $document): string
    {
        return DocumentNaming::externalCode($this->documentType(), $document->id);
    }

    /**
     * Имя по неделе: следующий номер среди документов того же типа с этим префиксом.
     *
     * @param  TDocument  $document
     */
    private function weeklyName(Model $document, Carbon $date): string
    {
        $weekPrefix = DocumentNaming::weekPrefix($this->namePrefix(), $date);
        $sequence   = DocumentNaming::nextSequence(
            $document::query()
                ->where('moysklad_processing_name', 'like', $weekPrefix . '%')
                ->pluck('moysklad_processing_name'),
            $weekPrefix
        );

        return DocumentNaming::weeklyName($this->namePrefix(), $sequence, $date);
    }

    private function logPrefix(string $method): string
    {
        return class_basename(static::class) . '::' . $method;
    }
}
