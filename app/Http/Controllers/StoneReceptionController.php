<?php

namespace App\Http\Controllers;

use App\Models\RawMaterialBatch;
use App\Models\StoneReception;
use App\Models\Worker;
use App\Models\Product;
use App\Models\Store;
use App\Traits\ManagesStock;
use App\Traits\HandlesReceptionValidation;
use App\Traits\HandlesBatchStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StoneReceptionController extends Controller
{
    use ManagesStock, HandlesReceptionValidation, HandlesBatchStock;

    /**
     * ID склада по умолчанию (6. Склад Уралия Цех)
     */
    const DEFAULT_STORE_ID = '0b1972f7-5e59-11ec-0a80-0698000bf502';

    /**
     * Загружает общие данные для форм создания и редактирования
     *
     * @param StoneReception|null $reception Приемка для редактирования (опционально)
     * @return array Массив с данными для формы
     */
    private function getFormData(StoneReception $reception = null, $selectedCutterId = null)
    {
        $data = [
            'masterWorkers' => Worker::where('position', 'Мастер')->orderBy('name')->get(),
            'workers' => Worker::orderBy('name')->get(),
            'products' => Product::orderBy('name')->get(),
            'stores' => Store::orderBy('name')->get(),
            'defaultStore' => Store::find(env('DEFAULT_STORE_ID', self::DEFAULT_STORE_ID)),
            'activeBatches' => collect(),
        ];

        // Если редактируем приемку
        if ($reception) {
            $reception->load('items', 'rawMaterialBatch.currentWorker');
            $data['stoneReception'] = $reception;

            // Получаем активные партии для пильщика из этой приемки
            if ($reception->cutter_id) {
                $batches = $this->getActiveBatches($reception->cutter_id);

                // Всегда добавляем текущую партию (даже если она неактивна)
                if ($reception->rawMaterialBatch) {
                    $currentBatch = $reception->rawMaterialBatch;

                    // Проверяем, есть ли уже эта партия в списке
                    $exists = $batches->contains('id', $currentBatch->id);

                    if (!$exists) {
                        // Создаем копию с правильным отображением
                        $currentBatchCopy = clone $currentBatch;
                        $currentBatchCopy->remaining_quantity = $currentBatch->remaining_quantity + $reception->raw_quantity_used;
                        $batches->prepend($currentBatchCopy);
                    }
                }

                $data['activeBatches'] = $batches;
            }
        }// Если редактируем приемку
        if ($reception) {
            $reception->load('items', 'rawMaterialBatch.currentWorker');
            $data['stoneReception'] = $reception;

            // Получаем активные партии для пильщика из этой приемки
            if ($reception->cutter_id) {
                $batches = $this->getActiveBatches($reception->cutter_id);

                // Всегда добавляем текущую партию (даже если она неактивна)
                if ($reception->rawMaterialBatch) {
                    $currentBatch = $reception->rawMaterialBatch;

                    // Проверяем, есть ли уже эта партия в списке
                    $exists = $batches->contains('id', $currentBatch->id);

                    if (!$exists) {
                        // Создаем копию с правильным отображением
                        $currentBatchCopy = clone $currentBatch;
                        $currentBatchCopy->remaining_quantity = $currentBatch->remaining_quantity + $reception->raw_quantity_used;
                        $batches->prepend($currentBatchCopy);
                    }
                }

                $data['activeBatches'] = $batches;
            }
        }
        // Если создаем новую приемку и выбран пильщик
        elseif ($selectedCutterId) {
            $data['activeBatches'] = $this->getActiveBatches($selectedCutterId);
        }

        return $data;
    }

    /**
     * Получает последние приемки для отображения
     *
     * @param int $limit Количество последних приемок
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function getLastReceptions($limit = 10)
    {
        return StoneReception::with(['receiver', 'cutter', 'store', 'items.product', 'rawMaterialBatch.product'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Отображает список всех приемок
     */
    public function index()
    {
        $receptions = StoneReception::with([
            'receiver',
            'cutter',
            'store',
            'items.product',
            'rawMaterialBatch.product'
        ])
            ->orderBy('created_at', 'desc')
            ->paginate(20); // Постраничная навигация по 20 записей

        return view('stone-receptions.index', compact('receptions'));
    }

    /**
     * Показывает форму для создания новой приемки
     */
    public function create(Request $request)
    {
        // Получаем ID пильщика из GET параметра
        $cutterId = $request->input('cutter_id');

        // Получаем ID партии из GET параметра
        $batchId = $request->input('raw_material_batch_id');

        // Получаем данные формы
        $data = $this->getFormData(null, $cutterId);

        // Добавляем последние приемки
        $data['lastReceptions'] = $this->getLastReceptions();

        // Получаем партии для выбранного пильщика
        if ($cutterId) {
            $data['filteredBatches'] = $this->getActiveBatches($cutterId);
        } else {
            $data['filteredBatches'] = collect();
        }

        $data['selectedCutterId'] = $cutterId;
        $data['selectedBatchId'] = $batchId;

        // Получаем данные для копирования из сессии
        $data['copiedData'] = session('copy_data');

        return view('stone-receptions.create', $data);
    }

    /**
     * Сохраняет новую приемку в базу данных
     *
     * @param Request $request HTTP запрос с данными формы
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        Log::info('Данные формы:', $request->all());

        try {
            // Проверяем корректность введенных данных
            $data = $this->validateReception($request, true);
            Log::info('Валидация пройдена', $data);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Ошибка валидации:', $e->errors());
            throw $e;
        }

        // Проверяем, выбран ли пильщик
        if (!$request->input('cutter_id')) {
            return back()
                ->withErrors(['cutter_id' => 'Выберите пильщика'])
                ->withInput();
        }

        // Проверяем остаток в выбранной партии
        $batch = RawMaterialBatch::find($data['raw_material_batch_id']);
        if (!$batch) {
            return back()
                ->withErrors(['raw_material_batch_id' => 'Партия сырья не найдена'])
                ->withInput();
        }

        if ($batch->remaining_quantity < $data['raw_quantity_used']) {
            return back()
                ->withErrors(['raw_quantity_used' => 'Недостаточно сырья в выбранной партии'])
                ->withInput();
        }

        try {
            // Используем транзакцию для гарантии целостности данных
            DB::transaction(function () use ($data) {
                // Создаем запись о приемке (updateStocks() вызовется автоматически через booted())
                $reception = StoneReception::create($this->prepareReceptionData($data));

                // Добавляем позиции (какие продукты получили)
                $this->createReceptionItems($reception, $data['products']);

                Log::info('Приемка успешно создана', [
                    'reception_id' => $reception->id,
                    'user_id' => auth()->id()
                ]);
            });

            // Очищаем данные копирования из сессии
            session()->forget('copy_data');

            return redirect()->route('stone-receptions.create', ['cutter_id' => $request->input('cutter_id')])
                ->with('success', 'Приемка успешно создана');

        } catch (\Exception $e) {
            Log::error('Ошибка при создании приемки', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);

            return back()
                ->withErrors(['error' => 'Произошла ошибка при создании приемки: ' . $e->getMessage()])
                ->withInput();
        }
    }

    /**
     * Показывает форму для редактирования существующей приемки
     *
     * @param StoneReception $stoneReception Приемка для редактирования
     */
    public function edit(StoneReception $stoneReception)
    {
        $data = $this->getFormData($stoneReception);
        $data['lastReceptions'] = $this->getLastReceptions();

        return view('stone-receptions.edit', $data);
    }

    /**
     * Обновляет существующую приемку в базе данных
     *
     * @param Request $request HTTP запрос с данными формы
     * @param StoneReception $stoneReception Приемка для обновления
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, StoneReception $stoneReception)
    {
        // Валидация данных
        $data = $this->validateReception($request, false);

        try {
            DB::transaction(function () use ($stoneReception, $data) {
                // Сначала возвращаем сырье в старую партию (если она изменилась)
                if ($stoneReception->raw_material_batch_id != $data['raw_material_batch_id']) {
                    // Возврат в старую партию
                    $oldBatch = RawMaterialBatch::find($stoneReception->raw_material_batch_id);
                    if ($oldBatch) {
                        $oldBatch->remaining_quantity += $stoneReception->raw_quantity_used;
                        $oldBatch->save();
                    }

                    // Проверка и списание из новой партии
                    $newBatch = RawMaterialBatch::find($data['raw_material_batch_id']);
                    if (!$newBatch || $newBatch->remaining_quantity < $data['raw_quantity_used']) {
                        throw new \Exception('Недостаточно сырья в новой партии');
                    }
                    $newBatch->remaining_quantity -= $data['raw_quantity_used'];
                    $newBatch->save();
                }
                // Если партия та же, но изменилось количество
                elseif ($stoneReception->raw_quantity_used != $data['raw_quantity_used']) {
                    $batch = RawMaterialBatch::find($data['raw_material_batch_id']);
                    $diff = $data['raw_quantity_used'] - $stoneReception->raw_quantity_used;

                    if ($diff > 0 && $batch->remaining_quantity < $diff) {
                        throw new \Exception('Недостаточно сырья в партии');
                    }

                    $batch->remaining_quantity -= $diff;
                    $batch->save();
                }

                // Обновляем основную информацию
                $stoneReception->update([
                    'receiver_id' => $data['receiver_id'],
                    'cutter_id' => $data['cutter_id'] ?? null,
                    'store_id' => $data['store_id'],
                    'raw_material_batch_id' => $data['raw_material_batch_id'],
                    'raw_quantity_used' => $data['raw_quantity_used'],
                    'notes' => $data['notes'] ?? null,
                ]);

                // Обновляем позиции продуктов
                $stoneReception->items()->delete();
                foreach ($data['products'] as $product) {
                    $stoneReception->items()->create([
                        'product_id' => $product['product_id'],
                        'quantity' => $product['quantity'],
                    ]);
                }

                Log::info('Приемка успешно обновлена', [
                    'reception_id' => $stoneReception->id,
                    'user_id' => auth()->id()
                ]);
            });

            return redirect()->route('stone-receptions.index')
                ->with('success', 'Приемка успешно обновлена');

        } catch (\Exception $e) {
            Log::error('Ошибка при обновлении приемки', [
                'error' => $e->getMessage(),
                'reception_id' => $stoneReception->id
            ]);

            return back()
                ->withErrors(['error' => 'Произошла ошибка при обновлении приемки: ' . $e->getMessage()])
                ->withInput();
        }
    }

    /**
     * Удаляет приемку из базы данных
     *
     * @param StoneReception $stoneReception Приемка для удаления
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(StoneReception $stoneReception)
    {
        try {
            // Используем транзакцию для гарантии целостности данных
            DB::transaction(function () use ($stoneReception) {
                $stoneReception->delete();

                Log::info('Приемка удалена', [
                    'reception_id' => $stoneReception->id,
                    'user_id' => auth()->id()
                ]);
            });

            return redirect()->route('stone-receptions.index')
                ->with('success', 'Приемка успешно удалена');

        } catch (\Exception $e) {
            Log::error('Ошибка при удалении приемки', [
                'error' => $e->getMessage(),
                'reception_id' => $stoneReception->id
            ]);

            return back()
                ->withErrors(['error' => 'Произошла ошибка при удалении приемки']);
        }
    }

    /**
     * Копирует существующую приемку для создания новой
     *
     * @param StoneReception $stoneReception Приемка для копирования
     * @return \Illuminate\Http\RedirectResponse
     */
    public function copy(Request $request, StoneReception $stoneReception)
    {
        try {
            // Загружаем позиции приемки
            $stoneReception->load('items');

            // Формируем данные для копирования
            $copyData = [
                'receiver_id' => $stoneReception->receiver_id,
                'notes' => $stoneReception->notes . ' (копия)',
                'products' => $stoneReception->items->map(fn($item) => [
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                ])->toArray()
            ];

            // Получаем текущие значения из формы
            $currentCutterId = $request->input('cutter_id');
            $currentBatchId = $request->input('raw_material_batch_id');

            // Сохраняем в сессию данные для копирования
            session()->put('copy_data', $copyData);

            // Если есть текущий пильщик, сохраняем его в отдельную сессию
            if ($currentCutterId) {
                session()->put('copy_cutter_id', $currentCutterId);
            }

            // Если есть текущая партия, сохраняем её
            if ($currentBatchId) {
                session()->put('copy_batch_id', $currentBatchId);
            }

            Log::info('Приемка скопирована', [
                'original_id' => $stoneReception->id,
                'user_id' => auth()->id(),
                'current_cutter_id' => $currentCutterId,
                'current_batch_id' => $currentBatchId
            ]);

            // Формируем URL с параметрами
            $params = [];
            if ($currentCutterId) {
                $params['cutter_id'] = $currentCutterId;
            }
            if ($currentBatchId) {
                $params['raw_material_batch_id'] = $currentBatchId;
            }

            return redirect()->route('stone-receptions.create', $params)
                ->with('success', 'Продукты скопированы. Проверьте данные и сохраните приемку.');

        } catch (\Exception $e) {
            Log::error('Ошибка при копировании приемки', [
                'error' => $e->getMessage(),
                'reception_id' => $stoneReception->id
            ]);

            return redirect()->route('stone-receptions.create')
                ->withErrors(['error' => 'Ошибка при копировании: ' . $e->getMessage()]);
        }
    }

    /**
     * Подготавливает данные для создания или обновления приемки
     *
     * @param array $data Исходные данные
     * @param bool $forCreate true для создания, false для обновления
     * @param bool $forCopy true для копирования
     * @return array Подготовленные данные
     */
    private function prepareReceptionData(array $data, bool $forCreate = true, bool $forCopy = false): array
    {
        $prepared = [
            'receiver_id' => $data['receiver_id'], // Кто принял
            'cutter_id' => $data['cutter_id'] ?? null, // Кто обработал
            'store_id' => $data['store_id'], // Склад назначения
            'raw_material_batch_id' => $data['raw_material_batch_id'], // Партия сырья
            'raw_quantity_used' => $data['raw_quantity_used'], // Использовано сырья
            'notes' => $data['notes'] ?? null, // Примечания
        ];

        // Для копирования не нужно добавлять временные метки
        if (!$forCopy) {
            if ($forCreate) {
                $prepared['created_at'] = now(); // Дата создания
            }
            $prepared['updated_at'] = now(); // Дата обновления
        }

        return $prepared;
    }

    /**
     * Создает позиции продуктов для приемки
     *
     * @param StoneReception $reception Приемка
     * @param array $products Массив продуктов с количествами
     */
    private function createReceptionItems(StoneReception $reception, array $products): void
    {
        foreach ($products as $product) {
            $reception->items()->create([
                'product_id' => $product['product_id'],
                'quantity' => $product['quantity'],
            ]);
        }
    }

    /**
     * Обновляет позиции продуктов для приемки
     *
     * @param StoneReception $reception Приемка
     * @param array $products Новый массив продуктов
     */
    private function updateReceptionItems(StoneReception $reception, array $products): void
    {
        // Удаляем старые позиции
        $reception->items()->delete();

        // Создаем новые позиции
        $this->createReceptionItems($reception, $products);
    }

    /**
     * Сбрасывает статус приемки на активный (для тестирования)
     *
     * @param StoneReception $stoneReception Приемка для сброса статуса
     * @return \Illuminate\Http\RedirectResponse
     */
    public function resetStatus(StoneReception $stoneReception)
    {
        // Используем константу из модели
        $stoneReception->update([
            'status' => StoneReception::STATUS_ACTIVE, // Статус "Активна"
            'moysklad_processing_id' => null, // Очищаем ID обработки в МойСклад
            'synced_at' => null // Очищаем дату синхронизации
        ]);

        Log::info('Статус приемки сброшен', [
            'reception_id' => $stoneReception->id,
            'user_id' => auth()->id()
        ]);

        return back()->with('success', 'Статус приемки сброшен на Активна');
    }
}
