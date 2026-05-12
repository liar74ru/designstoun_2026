<?php

namespace App\Http\Controllers;

use App\Services\Moysklad\CustomerOrderSyncService;
use App\Services\Moysklad\StockSyncService;
use App\Services\OrderService;
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

    public function sync(): RedirectResponse
    {
        $orders = $this->sync->pullActive();
        $stocks = $this->stockSync->syncAllProductsStocksByStores();

        $message = "Заявки: {$orders['message']} Остатки: {$stocks['message']}";
        $flashKey = ($orders['success'] && $stocks['success']) ? 'success' : 'error';

        return redirect()->route('orders.index')->with($flashKey, $message);
    }
}
