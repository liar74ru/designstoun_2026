@extends('layouts.app')

@section('title', 'Синхронизация с МойСклад')

@section('content')
    <div class="container py-3 py-md-4">

        <x-page-header title="🔄 Синхронизация с МойСклад" :hide-mobile="true">
            <x-slot:actions>
                <a href="{{ route('home') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> На главную
                </a>
            </x-slot:actions>
        </x-page-header>

        @include('partials.alerts')

        <p class="text-muted small mb-3">
            Запустите загрузку справочников или обновление остатков из МойСклад. Операция может занять несколько минут — не закрывайте вкладку до завершения.
        </p>

        <div class="row g-3">

            {{-- 1. Товары и группы --}}
            <div class="col-12 col-md-6 col-lg-4">
                <div class="card sync-card sync-card--primary h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-box-seam text-primary me-1"></i> Товары и группы
                            </h5>
                        </div>
                        <p class="card-text text-muted small mb-3">
                            Каталог и дерево групп из МойСклад
                        </p>
                        <form method="GET" action="{{ route('products.sync') }}" class="mt-auto sync-form">
                            <button type="submit" class="btn btn-primary w-100"
                                    onclick="return confirm('Загрузить/обновить товары и группы из МойСклад?')">
                                <i class="bi bi-arrow-repeat"></i> Синхронизировать
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            {{-- 2. Контрагенты --}}
            <div class="col-12 col-md-6 col-lg-4">
                <div class="card sync-card sync-card--info h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-people text-info me-1"></i> Контрагенты
                            </h5>
                        </div>
                        <p class="card-text text-muted small mb-3">
                            Поставщики и покупатели из МойСклад
                        </p>
                        <form method="POST" action="{{ route('counterparties.sync') }}" class="mt-auto sync-form">
                            @csrf
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-arrow-repeat"></i> Синхронизировать
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            {{-- 3. Склады --}}
            <div class="col-12 col-md-6 col-lg-4">
                <div class="card sync-card sync-card--success h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-building text-success me-1"></i> Склады
                            </h5>
                        </div>
                        <p class="card-text text-muted small mb-3">
                            Места хранения из МойСклад
                        </p>
                        <form method="POST" action="{{ route('stores.sync') }}" class="mt-auto sync-form">
                            @csrf
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-arrow-repeat"></i> Синхронизировать
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            {{-- 4. Остатки --}}
            <div class="col-12 col-md-6 col-lg-4">
                <div class="card sync-card sync-card--warning h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-stack text-warning me-1"></i> Остатки
                            </h5>
                        </div>
                        <p class="card-text text-muted small mb-3">
                            Текущие остатки всех товаров по всем складам
                        </p>
                        <form method="POST" action="{{ route('products.stocks.sync-all-by-stores') }}" class="mt-auto sync-form">
                            @csrf
                            <button type="submit" class="btn btn-primary w-100"
                                    onclick="return confirm('Обновить остатки по ВСЕМ складам? Это может занять несколько минут.')">
                                <i class="bi bi-arrow-repeat"></i> Синхронизировать
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            {{-- 5. Заказы покупателей --}}
            <div class="col-12 col-md-6 col-lg-4">
                <div class="card sync-card sync-card--danger h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-bag text-danger me-1"></i> Заказы покупателей
                            </h5>
                        </div>
                        <p class="card-text text-muted small mb-3">
                            Активные заявки покупателей из МойСклад
                        </p>
                        <form method="POST" action="{{ route('orders.sync') }}" class="mt-auto sync-form">
                            @csrf
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-arrow-repeat"></i> Синхронизировать
                            </button>
                        </form>
                    </div>
                </div>
            </div>

        </div>

    </div>
@endsection

@push('styles')
    <style>
        .sync-card {
            border: 1px solid var(--bs-border-color);
            border-left-width: 4px;
            border-radius: .4rem;
            transition: box-shadow .2s ease;
        }

        .sync-card:hover {
            box-shadow: 0 .25rem .75rem rgba(0, 0, 0, .08);
        }

        .sync-card--primary { border-left-color: var(--bs-primary); }
        .sync-card--info    { border-left-color: var(--bs-info);    }
        .sync-card--success { border-left-color: var(--bs-success); }
        .sync-card--warning { border-left-color: var(--bs-warning); }
        .sync-card--danger  { border-left-color: var(--bs-danger);  }

        .sync-card .card-title {
            font-size: 1rem;
            font-weight: 600;
        }

        .sync-form button[disabled] {
            opacity: .7;
            cursor: wait;
        }
    </style>
@endpush

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('form.sync-form').forEach(function (form) {
                form.addEventListener('submit', function () {
                    const btn = form.querySelector('button[type=submit]');
                    if (!btn) return;
                    btn.disabled = true;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Выполняется…';
                });
            });
        });
    </script>
@endpush
