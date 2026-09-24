<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderState;
use App\Services\Moysklad\OrderStateSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    /** Сохранить галочки «используется» и «производственный». */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled'      => ['nullable', 'array'],
            'enabled.*'    => ['string', 'exists:order_states,id'],
            'production'   => ['nullable', 'array'],
            'production.*' => ['string', 'exists:order_states,id'],
        ]);

        $enabled    = $data['enabled'] ?? [];
        $production = $data['production'] ?? [];

        OrderState::whereIn('id', $enabled)->update(['is_enabled' => true]);
        OrderState::whereNotIn('id', $enabled ?: ['-'])->update(['is_enabled' => false]);

        OrderState::whereIn('id', $production)->update(['is_production' => true]);
        OrderState::whereNotIn('id', $production ?: ['-'])->update(['is_production' => false]);

        // Массовый update событий модели не поднимает — чистим кэш явно.
        Order::forgetStateCache();

        return redirect()->route('admin.order-states.index')
            ->with('success', 'Список используемых статусов сохранён: ' . count($enabled) . '.');
    }
}
