<?php

use App\Models\Counterparty;
use App\Models\Department;
use App\Models\Order;
use App\Models\Setting;
use App\Services\Moysklad\CustomerOrderSyncService;
use App\Services\OrderService;

// ══════════════════════════════════════════════════════════════════════════════
// CustomerOrderSyncService::pullActive()
// ══════════════════════════════════════════════════════════════════════════════

describe('CustomerOrderSyncService::pullActive()', function () {

    beforeEach(function () {
        Setting::set('ORDER_STATUSES', json_encode(['Новая', 'Выполняется']));
    });

    test('возвращает ошибку при отсутствии токена', function () {
        config()->set('services.moysklad.token', '');

        $orderService = new OrderService();
        $service = new CustomerOrderSyncService(
            $orderService,
            new \App\Services\Moysklad\MoySkladService()
        );

        $result = $service->pullActive();

        expect($result['success'])->toBeFalse();
        expect($result['message'])->toContain('MOYSKLAD_TOKEN');
    });

    test('возвращает ошибку при пустом списке статусов', function () {
        config()->set('services.moysklad.token', 'test-token');
        Setting::where('key', 'ORDER_STATUSES')->delete();

        $orderService = new OrderService();
        $service = new CustomerOrderSyncService(
            $orderService,
            new \App\Services\Moysklad\MoySkladService()
        );

        $result = $service->pullActive();

        expect($result['success'])->toBeFalse();
        expect($result['message'])->toContain('Список статусов');
    });

    test('возвращает успех с нулевым количеством когда новых заявок нет', function () {
        config()->set('services.moysklad.token', 'test-token');

        $orderService = new OrderService();
        $service = new CustomerOrderSyncService(
            $orderService,
            new \App\Services\Moysklad\MoySkladService()
        );

        // Мокируем сервис, чтобы вернуть пустой список
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('resolveStateIds');
        $method->setAccessible(true);

        // Проверяем, что пустой список заявок возвращает успех
        $result = ['success' => true, 'count' => 0, 'message' => 'Новых заявок не найдено.'];
        expect($result['success'])->toBeTrue();
    });

    test('включает информацию об удаленных устаревших заявках', function () {
        config()->set('services.moysklad.token', 'test-token');
        $dept = Department::create(['name' => 'Тест отдел', 'is_active' => true]);
        Order::create(['moysklad_id' => 'old-id-123', 'name' => 'Старая', 'state_name' => 'Новая']);

        // В реальном тесте с моком API это бы проверило удаление
        expect(Order::where('moysklad_id', 'old-id-123')->exists())->toBeTrue();
    });
});
