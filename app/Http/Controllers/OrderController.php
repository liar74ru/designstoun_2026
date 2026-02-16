<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        if ($request->has('moysklad')) {
            return $this->fetchFromMoySklad($request);
        }

        $orders = Order::latest('moment')->paginate(20);
        return view('orders.index', compact('orders'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('orders.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sum' => 'required|numeric|min:0',
            'agent_name' => 'nullable|string',
            'moment' => 'nullable|date',
        ]);

        Order::create($validated);

        return redirect()->route('orders.index')
            ->with('success', 'Заказ успешно создан');
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $order = Order::where('moysklad_id', $id)->firstOrFail();
        return view('orders.show', compact('order'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        $order = Order::where('moysklad_id', $id)->firstOrFail();
        return view('orders.edit', compact('order'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $order = Order::where('moysklad_id', $id)->firstOrFail();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sum' => 'required|numeric|min:0',
            'agent_name' => 'nullable|string',
            'state' => 'nullable|string',
        ]);

        $order->update($validated);

        return redirect()->route('orders.show', $order->moysklad_id)
            ->with('success', 'Заказ обновлен');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $order = Order::where('moysklad_id', $id)->firstOrFail();
        $order->delete();

        return redirect()->route('orders.index')
            ->with('success', 'Заказ удален');
    }

    /**
     * Синхронизация заказов с МойСклад
     */
    public function syncFromMoySklad(Request $request)
    {
        $login = env('MOYSKLAD_LOGIN');
        $password = env('MOYSKLAD_PASSWORD');

        try {
            $response = Http::withBasicAuth($login, $password)
                ->withHeaders(['Accept-Encoding' => 'gzip'])
                ->get('https://api.moysklad.ru/api/remap/1.2/entity/customerorder', [
                    'limit' => 100,
                    'order' => 'moment,desc',
                    'expand' => 'agent,state',
                ]);

            if (!$response->successful()) {
                return redirect()->route('orders.index')
                    ->with('error', 'Ошибка API МойСклад');
            }

            $data = $response->json();
            $moyskladOrders = $data['rows'] ?? [];
            $synced = 0;

            foreach ($moyskladOrders as $item) {
                Order::updateOrCreate(
                    ['moysklad_id' => $item['id']],
                    [
                        'name' => $item['name'] ?? '',
                        'sum' => ($item['sum'] ?? 0) / 100,
                        'payed_sum' => ($item['payedSum'] ?? 0) / 100,
                        'shipped_sum' => ($item['shippedSum'] ?? 0) / 100,
                        'state' => $item['state']['id'] ?? null,
                        'state_name' => $item['state']['name'] ?? null,
                        'agent_name' => $item['agent']['name'] ?? null,
                        'moment' => $item['moment'] ?? null,
                    ]
                );
                $synced++;
            }

            return redirect()->route('orders.index')
                ->with('success', "Синхронизация завершена. Обновлено заказов: $synced");

        } catch (\Exception $e) {
            Log::error('Ошибка синхронизации заказов', ['error' => $e->getMessage()]);
            return redirect()->route('orders.index')
                ->with('error', 'Ошибка: ' . $e->getMessage());
        }
    }

    /**
     * Обновить конкретный заказ из МойСклад
     */
    public function refresh($id)
    {
        $login = env('MOYSKLAD_LOGIN');
        $password = env('MOYSKLAD_PASSWORD');

        try {
            $response = Http::withBasicAuth($login, $password)
                ->withHeaders(['Accept-Encoding' => 'gzip'])
                ->get("https://api.moysklad.ru/api/remap/1.2/entity/customerorder/{$id}", [
                    'expand' => 'agent,state'
                ]);

            if (!$response->successful()) {
                return back()->with('error', 'Не удалось обновить заказ');
            }

            $item = $response->json();

            $order = Order::updateOrCreate(
                ['moysklad_id' => $item['id']],
                [
                    'name' => $item['name'] ?? '',
                    'sum' => ($item['sum'] ?? 0) / 100,
                    'payed_sum' => ($item['payedSum'] ?? 0) / 100,
                    'shipped_sum' => ($item['shippedSum'] ?? 0) / 100,
                    'state' => $item['state']['id'] ?? null,
                    'state_name' => $item['state']['name'] ?? null,
                    'agent_name' => $item['agent']['name'] ?? null,
                    'moment' => $item['moment'] ?? null,
                ]
            );

            return redirect()->route('orders.show', $order->moysklad_id)
                ->with('success', 'Заказ обновлен');

        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Получить заказы из МойСклад (для отображения)
     */
    private function fetchFromMoySklad(Request $request)
    {
        $login = env('MOYSKLAD_LOGIN');
        $password = env('MOYSKLAD_PASSWORD');

        try {
            $response = Http::withBasicAuth($login, $password)
                ->withHeaders(['Accept-Encoding' => 'gzip'])
                ->get('https://api.moysklad.ru/api/remap/1.2/entity/customerorder', [
                    'limit' => 50,
                    'order' => 'moment,desc',
                    'expand' => 'agent,state',
                ]);

            if (!$response->successful()) {
                return redirect()->route('orders.index')
                    ->with('error', 'Ошибка API МойСклад');
            }

            $data = $response->json();
            $moyskladOrders = $data['rows'] ?? [];

            $orders = collect($moyskladOrders)->map(function($item) {
                return (object) [
                    'moysklad_id' => $item['id'] ?? null,
                    'name' => $item['name'] ?? 'Без номера',
                    'sum' => ($item['sum'] ?? 0) / 100,
                    'payed_sum' => ($item['payedSum'] ?? 0) / 100,
                    'state_name' => $item['state']['name'] ?? 'Новый',
                    'agent_name' => $item['agent']['name'] ?? 'Не указан',
                    'moment' => $item['moment'] ?? null,
                ];
            });

            return view('orders.moysklad', [
                'orders' => $orders,
                'total' => $data['meta']['size'] ?? 0
            ]);

        } catch (\Exception $e) {
            return redirect()->route('orders.index')
                ->with('error', 'Ошибка: ' . $e->getMessage());
        }
    }
}
