<?php

namespace App\Providers;

use App\Models\User;
use App\Models\Worker;
use App\Policies\WorkerPolicy;
use App\Support\OperationAccessor;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }

        foreach (array_keys(config('department_operations', [])) as $key) {
            Gate::define("see-{$key}", fn (?User $user) => OperationAccessor::canSee($user, $key));
        }
        Gate::define('manage-admin', fn (?User $user) => (bool) $user?->isAdmin());

        Gate::policy(Worker::class, WorkerPolicy::class);

        View::composer('layouts.partials.header', function ($view) {
            $view->with('operationsRegistry', config('department_operations'));
        });
    }
}
