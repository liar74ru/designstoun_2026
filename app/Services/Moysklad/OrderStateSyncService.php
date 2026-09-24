<?php

namespace App\Services\Moysklad;

use App\Models\Order;
use App\Models\OrderState;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

class OrderStateSyncService extends MoySkladBaseService
{
    /** Ключ старой настройки со списком имён статусов — только для первичного наполнения. */
    private const LEGACY_SETTING_KEY = 'MOYSKLAD_ORDER_STATUSES';

    /**
     * Перечитать справочник статусов заказа покупателя из метаданных МойСклад.
     *
     * Статусы, пропавшие из МойСклад, не удаляем, а помечаем archived: на них
     * ссылается orders.state_moysklad_id, и удаление обнулило бы имя и цвет
     * у исторических заявок.
     *
     * @return array{success: bool, synced: int, updated: int, archived: int, message: string}
     */
    public function sync(): array
    {
        $result = ['success' => false, 'synced' => 0, 'updated' => 0, 'archived' => 0, 'message' => ''];

        if (! $this->hasCredentials()) {
            $result['message'] = 'MOYSKLAD_TOKEN не установлен';

            return $result;
        }

        try {
            $meta = $this->get('/entity/customerorder/metadata');
            $states = $meta['states'] ?? [];

            if (empty($states)) {
                $result['message'] = 'МойСклад не вернул ни одного статуса';

                return $result;
            }

            $wasEmpty = OrderState::count() === 0;
            $legacyNames = $wasEmpty ? $this->legacyEnabledNames() : [];

            $seenIds = [];

            foreach ($states as $position => $state) {
                $id = $state['id'] ?? null;
                if (! $id) {
                    continue;
                }

                $seenIds[] = $id;

                $values = [
                    'name'       => $state['name'] ?? '',
                    'color'      => isset($state['color']) ? (int) $state['color'] : null,
                    'state_type' => $state['stateType'] ?? null,
                    'position'   => $position,
                    'archived'   => false,
                ];

                // Первое наполнение: переносим галочки из старой настройки по именам.
                if ($wasEmpty) {
                    $values['is_enabled'] = in_array($values['name'], $legacyNames, true);
                }

                $existing = OrderState::find($id);
                $existing ? $result['updated']++ : $result['synced']++;

                OrderState::updateOrCreate(['id' => $id], $values);
            }

            $result['archived'] = OrderState::whereNotIn('id', $seenIds)
                ->where('archived', false)
                ->update(['archived' => true]);

            Order::forgetStateCache();

            $result['success'] = true;
            $result['message'] = "Добавлено: {$result['synced']}, обновлено: {$result['updated']}"
                . ($result['archived'] > 0 ? ", пропало из МойСклад: {$result['archived']}" : '');

            Log::info('Синхронизация статусов заявок завершена: ' . $result['message']);
        } catch (\Throwable $e) {
            Log::error('Ошибка синхронизации статусов заявок', ['error' => $e->getMessage()]);
            $result['message'] = 'Ошибка синхронизации: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Имена статусов из старой настройки — использовались до появления справочника.
     *
     * @return array<int, string>
     */
    private function legacyEnabledNames(): array
    {
        return json_decode(Setting::get(self::LEGACY_SETTING_KEY, '[]'), true) ?: [];
    }
}
