<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** Базы PostgreSQL, которые тестам можно пересоздавать: локальная и CI. */
    private const TEST_DATABASES = ['designstoun_test', 'testing'];

    /**
     * RefreshDatabase делает migrate:fresh. Проверка — здесь, до него: опечатка в DB_DATABASE
     * стёрла бы рабочую базу.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $driver     = $app['config']->get("database.connections.{$connection}.driver");
        $database   = $app['config']->get("database.connections.{$connection}.database");

        if ($driver === 'pgsql' && !in_array($database, self::TEST_DATABASES, true)) {
            throw new RuntimeException(
                "Тесты пересоздают БД, а «{$database}» не тестовая. Допустимы: " . implode(', ', self::TEST_DATABASES)
            );
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Тест без Http::fake не должен дойти до настоящего МойСклад: запрос бросает
        // исключение, а не создаёт документ в рабочем аккаунте. Токен в тестах тоже пуст
        // (phpunit.xml); тест, которому нужен токен, задаёт его сам и фейкит ответы.
        Http::preventStrayRequests();
    }
}
