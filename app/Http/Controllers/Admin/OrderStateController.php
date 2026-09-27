<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderState;
use App\Services\Moysklad\OrderStateSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class OrderStateController extends Controller
{
    public function __construct(private OrderStateSyncService $sync)
    {
    }

    public function index(): View
    {
        $states = OrderState::orderBy('archived')->orderBy('position')->get();

        return view('admin.order-states.index', compact('states'));
    }

    /** Перечитать справочник из МойСклад. */
    public function sync(): RedirectResponse
    {
        $result = $this->sync->sync();

        return redirect()->route('admin.order-states.index')
            ->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Сохранить галочки «используется», «производственный», «в списке по умолчанию»,
     * «в конец списка», «следить за изменениями» и выбор статуса «Изменено».
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled'          => ['nullable', 'array'],
            'enabled.*'        => ['string', 'exists:order_states,id'],
            'production'       => ['nullable', 'array'],
            'production.*'     => ['string', 'exists:order_states,id'],
            'default_filter'   => ['nullable', 'array'],
            'default_filter.*' => ['string', 'exists:order_states,id'],
            'list_bottom'      => ['nullable', 'array'],
            'list_bottom.*'    => ['string', 'exists:order_states,id'],
            'track_changes'    => ['nullable', 'array'],
            'track_changes.*'  => ['string', 'exists:order_states,id'],
            'changed_state'    => ['nullable', 'string', 'exists:order_states,id'],
        ]);

        $enabled       = $data['enabled'] ?? [];
        $production    = $data['production'] ?? [];
        $defaultFilter = $data['default_filter'] ?? [];
        $listBottom    = $data['list_bottom'] ?? [];
        $trackChanges  = $data['track_changes'] ?? [];
        $changedState  = $data['changed_state'] ?? null;

        // Статус «Изменено» не может быть неиспользуемым: заявку в нём синхронизация
        // удалила бы вместе с отметками мастера.
        if ($changedState && ! in_array($changedState, $enabled, true)) {
            $enabled[] = $changedState;
        }

        // Одной транзакцией: упавший запрос не должен оставить галочки сохранёнными наполовину.
        DB::transaction(function () use ($enabled, $production, $defaultFilter, $listBottom, $trackChanges, $changedState) {
            $this->syncFlag('is_enabled', $enabled);
            $this->syncFlag('is_production', $production);
            $this->syncFlag('is_default_filter', $defaultFilter);
            $this->syncFlag('is_list_bottom', $listBottom);
            $this->syncFlag('track_changes', $trackChanges);
            $this->syncFlag('is_changed', $changedState ? [$changedState] : []);
        });

        // Массовый update событий модели не поднимает — чистим кэш явно.
        Order::forgetStateCache();

        return redirect()->route('admin.order-states.index')
            ->with('success', 'Список используемых статусов сохранён: ' . count($enabled) . '.');
    }

    /**
     * Флаг true у перечисленных статусов, false у остальных. Пустой список — сброс у всех:
     * заглушка вида whereNotIn(['-']) в PostgreSQL падает, id статуса — uuid.
     *
     * @param  array<int, string>  $ids
     */
    private function syncFlag(string $column, array $ids): void
    {
        if ($ids === []) {
            OrderState::query()->update([$column => false]);

            return;
        }

        OrderState::whereIn('id', $ids)->update([$column => true]);
        OrderState::whereNotIn('id', $ids)->update([$column => false]);
    }
}
