<?php

use App\Services\Moysklad\StockSyncService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Полная синхронизация остатков из МойСклад. Страховка к точечным обновлениям после
// документов программы: ловит движения, сделанные прямо в МойСклад.
Artisan::command('moysklad:sync-stocks', function (StockSyncService $stockSyncService) {
    $result = $stockSyncService->syncAllProductsStocksByStores();

    if ($result['success']) {
        Log::info('Синхронизация остатков из МойСклад', ['message' => $result['message']]);
        $this->info($result['message']);

        return 0;
    }

    Log::error('Синхронизация остатков из МойСклад не удалась', ['message' => $result['message']]);
    $this->error($result['message']);

    return 1;
})->purpose('Синхронизировать остатки всех товаров по складам из МойСклад');

// Требует cron на сервере: * * * * * php artisan schedule:run
Schedule::command('moysklad:sync-stocks')
    ->dailyAt('06:00')
    ->timezone('Europe/Moscow')
    ->withoutOverlapping();
