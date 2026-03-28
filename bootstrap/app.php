<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'worker.only' => \App\Http\Middleware\WorkerOnly::class,
            'master.only' => \App\Http\Middleware\MasterOnly::class,
        ]);
        // Применяем ко всем веб-маршрутам — каждый middleware сам проверяет роль пользователя
        $middleware->web(append: [
            \App\Http\Middleware\WorkerOnly::class,
            \App\Http\Middleware\MasterOnly::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
