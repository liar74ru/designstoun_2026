<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\MoySkladService;
use App\Services\StockSyncService;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class StoreController extends Controller
{
    private $moySkladService;

    public function __construct(MoySkladService $moySkladService)
    {
        $this->moySkladService = $moySkladService;
    }

    /**
     * Display a listing of all stores.
     */
    public function index(): View
    {
        $stores = Store::query()
            ->where('archived', false)
            ->withCount('stocks')
            ->orderBy('name')
            ->get();

        return view('stores.index', [
            'stores' => $stores,
        ]);
    }

    /**
     * Display a single store.
     */
    public function show(Store $store): View
    {

        return view('stores.show', [
            'store' => $store,
        ]);
    }

    /**
     * Synchronize stores from MoySklad
     */
    public function sync(): RedirectResponse
    {
        $result = $this->moySkladService->syncStores();

        if ($result['success']) {
            return redirect()->route('stores.index')
                ->with('success', $result['message']);
        } else {
            return redirect()->route('stores.index')
                ->with('error', $result['message']);
        }
    }
    /**
     * Синхронизировать остатки по всем складам
     */
    public function syncAllStocks(StockSyncService $stockSyncService)
    {
        $result = $stockSyncService->syncAllStocksByStores();

        if ($result['success']) {
            return redirect()->route('stores.index')
                ->with('success', $result['message']);
        } else {
            return redirect()->route('stores.index')
                ->with('error', $result['message']);
        }
    }

    /**
     * Синхронизировать остатки для конкретного склада
     */
    public function syncStoreStocks(Store $store, StockSyncService $stockSyncService)
    {
        $result = $stockSyncService->syncStocksByStore($store->id);

        if ($result['success']) {
            return redirect()->route('stores.index')
                ->with('success', "Склад '{$store->name}': {$result['message']}");
        } else {
            return redirect()->route('stores.index')
                ->with('error', "Склад '{$store->name}': {$result['message']}");
        }
    }
}
