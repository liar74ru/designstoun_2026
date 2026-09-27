<?php

namespace App\Providers;

use App\Models\RawMaterialBatch;
use App\Models\StoneReception;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Models\Worker;
use App\Models\Workshop;
use App\Policies\RawMaterialBatchPolicy;
use App\Policies\StoneReceptionPolicy;
use App\Policies\SupplierOrderPolicy;
use App\Policies\WorkerPolicy;
use App\Policies\WorkshopPolicy;
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

        // AJAX-эндпоинты товаров обслуживают сразу несколько операций реестра,
        // поэтому доступ — по составному gate, а не по одиночному see-{key}.
        Gate::define(
            'use-product-api',
            fn (?User $user) => OperationAccessor::canSeeAny($user, OperationAccessor::PRODUCT_API_OPERATIONS),
        );

        Gate::policy(Worker::class, WorkerPolicy::class);
        // Изменяющие действия над записью — только в своём отделе (DepartmentAccess)
        Gate::policy(StoneReception::class, StoneReceptionPolicy::class);
        Gate::policy(Workshop::class, WorkshopPolicy::class);
        Gate::policy(RawMaterialBatch::class, RawMaterialBatchPolicy::class);
        Gate::policy(SupplierOrder::class, SupplierOrderPolicy::class);

        View::composer('layouts.partials.header', function ($view) {
            $view->with('operationsRegistry', config('department_operations'));
        });
    }
}
