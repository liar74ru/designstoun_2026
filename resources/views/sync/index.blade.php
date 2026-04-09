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

        {{-- Навигационные плитки --}}
        <div class="row mb-4">
            <div class="col-md-3">
                <a href="{{ route('products.index') }}" class="text-decoration-none">
                    <div class="card bg-primary text-white h-100 shadow-sm hover-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title mb-1">Товары</h5>
                                    <small>справочник</small>
                                </div>
                                <i class="bi bi-box display-4 opacity-50"></i>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent border-0 text-end">
                            <small>Перейти <i class="bi bi-arrow-right"></i></small>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-md-3">
                <a href="{{ route('stores.index') }}" class="text-decoration-none">
                    <div class="card bg-success text-white h-100 shadow-sm hover-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title mb-1">Склады</h5>
                                    <small>мест хранения</small>
                                </div>
                                <i class="bi bi-building display-4 opacity-50"></i>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent border-0 text-end">
                            <small>Перейти <i class="bi bi-arrow-right"></i></small>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-md-3">
                <a href="{{ route('counterparties.index') }}" class="text-decoration-none">
                    <div class="card bg-info text-dark h-100 shadow-sm hover-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title mb-1">Контрагенты</h5>
                                    <small>поставщики</small>
                                </div>
                                <i class="bi bi-people display-4 opacity-50"></i>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent border-0 text-end">
                            <small>Перейти <i class="bi bi-arrow-right"></i></small>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-md-3">
                <a href="{{ route('stone-receptions.index') }}" class="text-decoration-none">
                    <div class="card bg-warning text-dark h-100 shadow-sm hover-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title mb-1">Приёмки</h5>
                                    <small>техоперации</small>
                                </div>
                                <i class="bi bi-journal-text display-4 opacity-50"></i>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent border-0 text-end">
                            <small>Перейти <i class="bi bi-arrow-right"></i></small>
                        </div>
                    </div>
                </a>
            </div>
        </div>

        {{-- ═══════════════════════ ФИЛЬТРЫ/СЕКЦИИ ═══════════════════════ --}}
        <form method="GET" id="section-form" class="card shadow-sm mb-2 mb-md-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-2"
                 style="cursor:pointer" id="section-toggle" role="button">
                <span class="fw-semibold text-muted small">
                    <i class="bi bi-list-ul me-1"></i> Операции синхронизации
                </span>
                <i class="bi bi-chevron-down" id="section-chevron"></i>
            </div>
            <div id="section-collapse" style="display:none">
                <div class="card-body pb-2">

                    {{-- Загрузка справочников --}}
                    <h6 class="text-muted mb-3 mt-2">
                        <i class="bi bi-cloud-download me-1"></i> Загрузка справочников из МойСклад
                    </h6>
                    <div class="row g-2 mb-3">
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="card h-100 border-primary">
                                <div class="card-body py-2">
                                    <h6 class="card-title fw-bold mb-1">
                                        <i class="bi bi-box text-primary"></i> Товары
                                    </h6>
                                    <p class="card-text text-muted small mb-2">Загрузить все товары и группы</p>
                                    <form method="GET" action="{{ route('products.sync') }}" class="d-inline">
                                        <button type="submit" class="btn btn-success btn-sm"
                                                onclick="return confirm('Загрузить/обновить товары и группы из МойСклад?')">
                                            <i class="bi bi-arrow-repeat"></i> Синхронизировать
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="card h-100 border-primary">
                                <div class="card-body py-2">
                                    <h6 class="card-title fw-bold mb-1">
                                        <i class="bi bi-folder text-primary"></i> Группы товаров
                                    </h6>
                                    <p class="card-text text-muted small mb-2">Загрузить дерево групп</p>
                                    <form method="GET" action="{{ route('products.groups.sync') }}" class="d-inline">
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="bi bi-arrow-repeat"></i> Синхронизировать
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="card h-100 border-primary">
                                <div class="card-body py-2">
                                    <h6 class="card-title fw-bold mb-1">
                                        <i class="bi bi-people text-primary"></i> Контрагенты
                                    </h6>
                                    <p class="card-text text-muted small mb-2">Загрузить поставщиков и покупателей</p>
                                    <form method="POST" action="{{ route('counterparties.sync') }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="bi bi-arrow-repeat"></i> Синхронизировать
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="card h-100 border-primary">
                                <div class="card-body py-2">
                                    <h6 class="card-title fw-bold mb-1">
                                        <i class="bi bi-building text-primary"></i> Склады
                                    </h6>
                                    <p class="card-text text-muted small mb-2">Загрузить список складов</p>
                                    <form method="POST" action="{{ route('stores.sync') }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="bi bi-arrow-repeat"></i> Синхронизировать
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Остатки --}}
                    <h6 class="text-muted mb-3 mt-3">
                        <i class="bi bi-box-seam me-1"></i> Остатки товаров
                    </h6>
                    <div class="row g-2 mb-3">
                        <div class="col-12 col-sm-6 col-lg-4">
                            <div class="card h-100 border-success">
                                <div class="card-body py-2">
                                    <h6 class="card-title fw-bold mb-1">
                                        <i class="bi bi-stack text-success"></i> Остатки по всем складам
                                    </h6>
                                    <p class="card-text text-muted small mb-2">Обновить остатки всех товаров</p>
                                    <form method="POST" action="{{ route('products.stocks.sync-all-by-stores') }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-success btn-sm"
                                                onclick="return confirm('Обновить остатки по ВСЕМ складам? Это может занять несколько минут.')">
                                            <i class="bi bi-arrow-repeat"></i> Обновить остатки
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Документы --}}
                    <h6 class="text-muted mb-3 mt-3">
                        <i class="bi bi-upload me-1"></i> Отправка документов в МойСклад
                    </h6>
                    <p class="text-muted small mb-2">Операции создания и обновления документов. Большинство выполняются автоматически.</p>
                    <div class="table-responsive mb-3">
                        <table class="table table-bordered table-sm align-middle">
                            <thead class="table-light">
                            <tr>
                                <th>Тип</th>
                                <th>Описание</th>
                                <th>Когда</th>
                                <th>Перейти</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr>
                                <td><span class="badge bg-info text-dark">Техоперация</span></td>
                                <td>Создание техоперации для приёмки камня</td>
                                <td>Автоматически при создании/редактировании приёмки</td>
                                <td><a href="{{ route('stone-receptions.index') }}" class="btn btn-outline-secondary btn-sm">Приёмки</a></td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-info text-dark">Перемещение</span></td>
                                <td>Перемещение для партии сырья</td>
                                <td>Автоматически при создании/редактировании партии</td>
                                <td><a href="{{ route('raw-batches.index') }}" class="btn btn-outline-secondary btn-sm">Партии</a></td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-warning text-dark">Заказ поставщику</span></td>
                                <td>Создание/обновление заказа поставщику</td>
                                <td>Автоматически при работе с поступлениями</td>
                                <td><a href="{{ route('supplier-orders.index') }}" class="btn btn-outline-secondary btn-sm">Поступления</a></td>
                            </tr>
                            <tr>
                                <td><span class="badge bg-warning text-dark">Приёмка (Supply)</span></td>
                                <td>Создание приёмки на склад</td>
                                <td>Вручную со страницы поступления</td>
                                <td><a href="{{ route('supplier-orders.index') }}" class="btn btn-outline-secondary btn-sm">Поступления</a></td>
                            </tr>
                            </tbody>
                        </table>
                    </div>

                    {{-- Разделы --}}
                    <h6 class="text-muted mb-3 mt-3">
                        <i class="bi bi-link-45deg me-1"></i> Разделы с синхронизацией
                    </h6>
                    <div class="row g-2">
                        <div class="col-6 col-sm-4 col-md-3">
                            <a href="{{ route('products.index') }}" class="text-decoration-none">
                                <div class="card h-100 shadow-sm hover-card border-primary">
                                    <div class="card-body text-center p-3">
                                        <i class="bi bi-box-seam fs-3 text-primary"></i>
                                        <h6 class="fw-bold mt-2 mb-1">Товары</h6>
                                        <p class="text-muted small mb-0">Товары и остатки</p>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="col-6 col-sm-4 col-md-3">
                            <a href="{{ route('stores.index') }}" class="text-decoration-none">
                                <div class="card h-100 shadow-sm hover-card border-success">
                                    <div class="card-body text-center p-3">
                                        <i class="bi bi-building fs-3 text-success"></i>
                                        <h6 class="fw-bold mt-2 mb-1">Склады</h6>
                                        <p class="text-muted small mb-0">Склады и остатки</p>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="col-6 col-sm-4 col-md-3">
                            <a href="{{ route('counterparties.index') }}" class="text-decoration-none">
                                <div class="card h-100 shadow-sm hover-card border-info">
                                    <div class="card-body text-center p-3">
                                        <i class="bi bi-people fs-3 text-info"></i>
                                        <h6 class="fw-bold mt-2 mb-1">Контрагенты</h6>
                                        <p class="text-muted small mb-0">Поставщики</p>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="col-6 col-sm-4 col-md-3">
                            <a href="{{ route('stone-receptions.index') }}" class="text-decoration-none">
                                <div class="card h-100 shadow-sm hover-card border-warning">
                                    <div class="card-body text-center p-3">
                                        <i class="bi bi-journal-text fs-3 text-warning"></i>
                                        <h6 class="fw-bold mt-2 mb-1">Приёмки</h6>
                                        <p class="text-muted small mb-0">Техоперации</p>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="col-6 col-sm-4 col-md-3">
                            <a href="{{ route('raw-batches.index') }}" class="text-decoration-none">
                                <div class="card h-100 shadow-sm hover-card border-secondary">
                                    <div class="card-body text-center p-3">
                                        <i class="bi bi-arrow-left-right fs-3 text-secondary"></i>
                                        <h6 class="fw-bold mt-2 mb-1">Партии сырья</h6>
                                        <p class="text-muted small mb-0">Перемещения</p>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="col-6 col-sm-4 col-md-3">
                            <a href="{{ route('supplier-orders.index') }}" class="text-decoration-none">
                                <div class="card h-100 shadow-sm hover-card border-danger">
                                    <div class="card-body text-center p-3">
                                        <i class="bi bi-plus-circle fs-3 text-danger"></i>
                                        <h6 class="fw-bold mt-2 mb-1">Поступления</h6>
                                        <p class="text-muted small mb-0">Заказы поставщикам</p>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>

                </div>
            </div>
        </form>

    </div>
@endsection

@push('styles')
    <style>
        .hover-card {
            transition: all 0.3s ease;
            border: none;
            position: relative;
            overflow: hidden;
        }

        .hover-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1.5rem rgba(0,0,0,0.2) !important;
        }

        .hover-card .display-4 {
            transition: transform 0.3s ease;
        }

        .hover-card:hover .display-4 {
            transform: scale(1.1);
        }

        .hover-card .card-footer {
            background: linear-gradient(to right, transparent, rgba(255,255,255,0.1));
            padding: 0.5rem 1rem;
        }

        .bg-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
        }

        .bg-success {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%) !important;
        }

        .bg-info {
            background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%) !important;
        }

        .bg-warning {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%) !important;
        }

        .row.mb-4 .col-md-3 {
            animation: slideInUp 0.5s ease forwards;
            opacity: 0;
        }

        .row.mb-4 .col-md-3:nth-child(1) { animation-delay: 0.1s; }
        .row.mb-4 .col-md-3:nth-child(2) { animation-delay: 0.2s; }
        .row.mb-4 .col-md-3:nth-child(3) { animation-delay: 0.3s; }
        .row.mb-4 .col-md-3:nth-child(4) { animation-delay: 0.4s; }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
@endpush

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const STORAGE_KEY = 'sync_section_collapsed';
            const collapse = document.getElementById('section-collapse');
            const chevron = document.getElementById('section-chevron');
            const toggle = document.getElementById('section-toggle');

            const userOpened = localStorage.getItem(STORAGE_KEY) === 'open';
            const shouldExpand = userOpened;

            function applyState(expanded, animate) {
                if (expanded) {
                    collapse.style.display = '';
                    if (animate) { collapse.style.opacity = '0'; setTimeout(() => collapse.style.opacity = '', 10); }
                    chevron.className = 'bi bi-chevron-up';
                } else {
                    if (animate) {
                        collapse.style.opacity = '0';
                        setTimeout(() => { collapse.style.display = 'none'; collapse.style.opacity = ''; }, 150);
                    } else {
                        collapse.style.display = 'none';
                    }
                    chevron.className = 'bi bi-chevron-down';
                }
            }

            applyState(shouldExpand, false);
            toggle.addEventListener('click', function () {
                const isHidden = collapse.style.display === 'none';
                applyState(isHidden, true);
                localStorage.setItem(STORAGE_KEY, isHidden ? 'open' : 'closed');
            });
        });
    </script>
@endpush
