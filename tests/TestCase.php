<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Тест без Http::fake не должен дойти до настоящего МойСклад: запрос бросает
        // исключение, а не создаёт документ в рабочем аккаунте. Токен в тестах тоже пуст
        // (phpunit.xml); тест, которому нужен токен, задаёт его сам и фейкит ответы.
        Http::preventStrayRequests();
    }
}
