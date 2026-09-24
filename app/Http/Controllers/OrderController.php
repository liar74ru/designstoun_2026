<?php

namespace App\Http\Controllers;

use App\Services\Moysklad\CustomerOrderSyncService;
use App\Services\Moysklad\StockSyncService;
use App\Services\OrderService;
use App\Services\OrderStockCorrectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function __construct(
        private OrderService $service,
        private CustomerOrderSyncService $sync,
        private StockSyncService $stockSync,
    ) {
    }

    public function index(Request $request): View
    {
        return view('orders.index', $this->service->getIndexData($request));
    }

    public function show(Request $request, string $moyskladId): View
    {
        return view('orders.show', $this->service->getShowData($request, $moyskladId));
    }

    /**
     * Уточнить фактический остаток позиции в рамках заявки.
     */
    public function storeCorrection(
        Request $request,
        OrderStockCorrectionService $corrections,
        string $moyskladId,
    ): RedirectResponse {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'store_id'   => 'required|string|exists:stores,id',
            'fact'       => 'required|numeric|min:0',
            'note'       => 'nullable|string|max:500',
        ], [
            'fact.required' => 'Укажите фактический остаток',
            'fact.min'      => 'Фактический остаток не может быть отрицательным',
        ]);

        $order = $this->service->findForUser($request, $moyskladId);

        $correction = $corrections->set(
            $order,
            (int) $data['product_id'],
            $data['store_id'],
            (float) $data['fact'],
            $data['note'] ?? null,
            $request->user(),
        );

        $message = $correction
            ? 'Остаток уточнён: ' . number_format((float) $data['fact'], 1, '.', '') . '.'
            : 'Остаток совпал с МойСклад — поправка снята.';

        return redirect()->route('orders.show', $moyskladId)->with('success', $message);
    }

    public function destroyCorrection(
        Request $request,
        OrderStockCorrectionService $corrections,
        string $moyskladId,
        int $productId,
    ): RedirectResponse {
        $order = $this->service->findForUser($request, $moyskladId);

        $corrections->reset($order, $productId, (string) $request->input('store_id'));

        return redirect()->route('orders.show', $moyskladId)
            ->with('success', 'Уточнение снято — показан остаток МойСклад.');
    }

    public function sync(): RedirectResponse
    {
        $orders = $this->sync->pullActive();
        $stocks = $this->stockSync->syncAllProductsStocksByStores();

        $message = "Заявки: {$orders['message']} Остатки: {$stocks['message']}";
        $flashKey = ($orders['success'] && $stocks['success']) ? 'success' : 'error';

        return redirect()->route('orders.index')->with($flashKey, $message);
    }
}
