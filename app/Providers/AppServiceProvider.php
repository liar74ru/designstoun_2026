<?php

namespace App\Providers;

use App\Models\Department;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }

        View::composer('layouts.partials.header', function ($view) {
            $user   = auth()->user();
            $deptId = $user?->worker?->department_id;

            $enabledKeys = $deptId
                ? Cache::remember(
                    Department::operationsCacheKey($deptId),
                    300,
                    fn () => Department::find($deptId)?->enabledOperationKeys() ?? [],
                )
                : [];

            $view->with([
                'operationsRegistry'   => config('department_operations'),
                'enabledOperationKeys' => $enabledKeys,
            ]);
        });
    }
}
