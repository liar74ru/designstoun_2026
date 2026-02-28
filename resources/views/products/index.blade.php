@extends('layouts.app')

@section('title', 'Товары')

@section('content')
    <div class="container py-4">
        <!-- Заголовок и кнопки действий -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2 mb-0">
                <i class="bi bi-box"></i> Товары в локальной базе
            </h1>

            <div class="btn-group">
                <a href="{{ route('products.sync') }}" class="btn btn-success"
                   onclick="return confirm('Загрузить/обновить товары и группы из МойСклад?')">
                    <i class="bi bi-cloud-download"></i> Синхронизировать
                </a>
                <a href="{{ route('products.create') }}" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> Добавить вручную
                </a>
            </div>
        </div>

        <!-- Сообщения -->
        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill"></i> {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill"></i> {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        <!-- Навигационные плитки -->
        <div class="row mb-4">
            <!-- Товары (текущий раздел) -->
            <div class="col-md-3">
                <a href="{{ route('products.index') }}" class="text-decoration-none">
                    <div class="card bg-primary text-white h-100 shadow-sm hover-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title mb-1">Товары</h5>
                                    <h2 class="mb-0">{{ $products->total() }}</h2>
                                    <small>всего наименований</small>
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

            <!-- Склады -->
            <div class="col-md-3">
                <a href="{{ route('stores.index') }}" class="text-decoration-none">
                    <div class="card bg-success text-white h-100 shadow-sm hover-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title mb-1">Склады</h5>
{{--                                    <h2 class="mb-0">{{ App\Models\Warehouse::count() }}</h2>--}}
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

            <!-- Группы товаров -->
            <div class="col-md-3">
                <a href="{{ route('products.groups') }}" class="text-decoration-none">
                    <div class="card bg-warning text-dark h-100 shadow-sm hover-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title mb-1">Группы</h5>
                                    <h2 class="mb-0">{{ App\Models\ProductGroup::count() }}</h2>
                                    <small>категорий товаров</small>
                                </div>
                                <i class="bi bi-folder display-4 opacity-50"></i>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent border-0 text-end">
                            <small>Перейти <i class="bi bi-arrow-right"></i></small>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Место для будущей плитки -->
            <div class="col-md-3">
                <a href="#" class="text-decoration-none">
                    <div class="card bg-secondary text-white h-100 shadow-sm hover-card opacity-75">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title mb-1">Заказы</h5>
                                    <h2 class="mb-0">0</h2>
                                    <small>в работе</small>
                                </div>
                                <i class="bi bi-cart display-4 opacity-50"></i>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent border-0 text-end">
                            <small>Скоро <i class="bi bi-arrow-right"></i></small>
                        </div>
                    </div>
                </a>
            </div>
        </div>

        <!-- Фильтры -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" action="{{ route('products.index') }}" class="row g-3">
                    <!-- Поиск -->
                    <div class="col-md-4">
                        <label class="form-label">Поиск</label>
                        <input type="text" name="filter[search]" class="form-control"
                               placeholder="Название, артикул..."
                               value="{{ request('filter.search') }}">
                    </div>

                    <!-- Фильтр по группе с древовидной структурой -->
                    <div class="col-md-3">
                        <label class="form-label">Группа товаров</label>
                        <div class="dropdown-tree" id="groupFilterDropdown">
                            <!-- Кнопка для открытия и текст на ней (выбранная категория или Все группы по умолчанию) -->
                            <button class="btn btn-outline-secondary w-100 text-start d-flex justify-content-between align-items-center dropdown-toggle"
                                    type="button"
                                    id="groupDropdownBtn"
                                    data-bs-toggle="dropdown"
                                    data-bs-auto-close="outside"
                                    aria-expanded="false">
            <span class="truncate-text">
                @if(request('filter.group_id'))  {{-- Изменено с request('group') --}}
                @php
                    $selectedGroup = App\Models\ProductGroup::where('moysklad_id', request('filter.group_id'))->first();
                @endphp
                <i class="bi bi-folder me-1"></i>
                {{ $selectedGroup ? $selectedGroup->name : 'Выбрана группа' }}
                @else
                    <i class="bi bi-folder me-1"></i>
                    Все группы
                @endif
            </span>
                            </button>

                            <!-- Выпадающее меню с деревом -->
                            <div class="dropdown-menu w-100 p-0" aria-labelledby="groupDropdownBtn" style="max-height: 400px; overflow-y: auto;">
                                <div class="p-2">
                                    <!-- Ссылка на все группы -->
                                    <a href="{{ route('products.index', array_merge(request()->except(['filter.group_id', 'page']), ['filter[group_id]' => ''])) }}"
                                    class="dropdown-item d-flex align-items-center justify-content-between {{ !request('filter.group_id') ? 'active' : '' }}">
                                        <span>
                        <i class="bi bi-folder me-2"></i>
                        Все группы
                    </span>
                                        <span class="badge {{ !request('filter.group_id') ? 'bg-light text-primary' : 'bg-secondary' }}">
                                            {{ App\Models\Product::count() }}
                    </span>
                                    </a>

                                    <div class="dropdown-divider"></div>

                                    <!-- Дерево групп -->
                                    <div class="tree-filter-wrapper">
                                        @include('products.partials.tree-filter', [
                                            'groups' => $groupsTree,
                                            'level' => 0,
                                            'activeGroup' => request('filter.group_id')
                                        ])
                                    </div>
                                </div>
                            </div>
                        </div>
                        <small class="text-muted">
                            <i class="bi bi-info-circle"></i>
                            Всего групп: {{ App\Models\ProductGroup::count() }},
                            товаров: {{ App\Models\Product::count() }}
                        </small>
                    </div>

                    <!-- Фильтр по наличию -->
                    <div class="col-md-2">
                        <label class="form-label">Наличие</label>
                        <select name="in_stock" class="form-select">
                            <option value="">Все</option>
                            <option value="1" {{ request('in_stock') == '1' ? 'selected' : '' }}>В наличии</option>
                            <option value="0" {{ request('in_stock') == '0' ? 'selected' : '' }}>Нет в наличии</option>
                        </select>
                    </div>

                    <!-- Фильтр по цене -->
                    <div class="col-md-3">
                        <label class="form-label">Цена</label>
                        <div class="input-group">
                            <input type="number" name="price_from" class="form-control"
                                   placeholder="От" value="{{ request('price_from') }}">
                            <input type="number" name="price_to" class="form-control"
                                   placeholder="До" value="{{ request('price_to') }}">
                        </div>
                    </div>

                    <!-- Кнопки -->
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> Применить фильтры
                        </button>
                        <a href="{{ route('products.index') }}" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle"></i> Сбросить
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Таблица товаров -->
        @if($products->count() > 0)
            <div class="card shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>
                                <a href="{{ route('products.index', array_merge(request()->all(), ['sort' => 'name', 'direction' => $sortField == 'name' && $sortDirection == 'asc' ? 'desc' : 'asc'])) }}"
                                   class="text-decoration-none text-dark">
                                    Название
                                    @if($sortField == 'name')
                                        <i class="bi bi-arrow-{{ $sortDirection == 'asc' ? 'up' : 'down' }}"></i>
                                    @endif
                                </a>
                            </th>
                            <th>Группа</th>
                            <th>
                                <a href="{{ route('products.index', array_merge(request()->all(), ['sort' => 'sku', 'direction' => $sortField == 'sku' && $sortDirection == 'asc' ? 'desc' : 'asc'])) }}"
                                   class="text-decoration-none text-dark">
                                    Артикул
                                    @if($sortField == 'sku')
                                        <i class="bi bi-arrow-{{ $sortDirection == 'asc' ? 'up' : 'down' }}"></i>
                                    @endif
                                </a>
                            </th>
                            <th>
                                <a href="{{ route('products.index', array_merge(request()->all(), ['sort' => 'price', 'direction' => $sortField == 'price' && $sortDirection == 'asc' ? 'desc' : 'asc'])) }}"
                                   class="text-decoration-none text-dark">
                                    Цена
                                    @if($sortField == 'price')
                                        <i class="bi bi-arrow-{{ $sortDirection == 'asc' ? 'up' : 'down' }}"></i>
                                    @endif
                                </a>
                            </th>
                            <th>
                                <a href="{{ route('products.index', array_merge(request()->all(), ['sort' => 'quantity', 'direction' => $sortField == 'quantity' && $sortDirection == 'asc' ? 'desc' : 'asc'])) }}"
                                   class="text-decoration-none text-dark">
                                    Остаток
                                    @if($sortField == 'quantity')
                                        <i class="bi bi-arrow-{{ $sortDirection == 'asc' ? 'up' : 'down' }}"></i>
                                    @endif
                                </a>
                            </th>
                            <th class="text-center">Действия</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($products as $product)
                            <tr>
                                <td>
                                    <a href="{{ route('products.show', $product->moysklad_id) }}"
                                       class="text-decoration-none fw-bold">
                                        {{ $product->name }}
                                    </a>
                                    @if($product->description)
                                        <br><small class="text-muted">{{ Str::limit($product->description, 50) }}</small>
                                    @endif
                                </td>
                                <td>
                                    @if($product->group_name)
                                        <span class="badge bg-info text-dark"
                                              title="{{ $product->group_name }}">
                                            <i class="bi bi-folder"></i>
                                            {{ Str::limit($product->group_name, 20) }}
                                        </span>
                                    @else
                                        <span class="badge bg-secondary">Без группы</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge bg-secondary">{{ $product->sku ?? '—' }}</span>
                                </td>
                                <td>
                                    <span class="fw-bold">{{ number_format($product->price, 2, ',', ' ') }} ₽</span>
                                    @if($product->old_price)
                                        <br>
                                        <small class="text-muted text-decoration-line-through">
                                            {{ number_format($product->old_price, 2, ',', ' ') }} ₽
                                        </small>
                                        @if($product->discount_percent)
                                            <span class="badge bg-danger">-{{ $product->discount_percent }}%</span>
                                        @endif
                                    @endif
                                </td>
                                <td>
                                    @if($product->quantity > 0)
                                        <span class="badge bg-success">{{ $product->quantity }} шт.</span>
                                    @else
                                        <span class="badge bg-danger">Нет</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="d-flex gap-2 justify-content-center">
                                        <a href="{{ route('products.show', $product->moysklad_id) }}"
                                           class="btn btn-sm btn-outline-info"
                                           title="Просмотр">
                                            <i class="bi bi-eye"></i>
                                        </a>

                                        <a href="{{ route('products.refresh', $product->moysklad_id) }}"
                                           class="btn btn-sm btn-outline-warning"
                                           title="Обновить из МойСклад"
                                           onclick="return confirm('Обновить данные товара из МойСклад?')">
                                            <i class="bi bi-arrow-repeat"></i>
                                        </a>

                                        <form action="{{ route('products.destroy', $product->moysklad_id) }}"
                                              method="POST"
                                              class="d-inline"
                                              onsubmit="return confirm('Вы уверены, что хотите удалить товар "{{ $product->name }}" из локальной базы?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="btn btn-sm btn-outline-danger"
                                                title="Удалить из локальной базы">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Пагинация -->
            <div class="d-flex justify-content-between align-items-center mt-4">
                <div>
                    Показано {{ $products->firstItem() }} - {{ $products->lastItem() }} из {{ $products->total() }} товаров
                </div>
                <div>
                    {{ $products->withQueryString()->links() }}
                </div>
            </div>
        @else
            <div class="text-center py-5">
                <div class="display-1 text-muted mb-4">📦</div>
                <h3 class="text-muted mb-3">Товары не найдены</h3>
                <p class="mb-4">
                    @if(request()->anyFilled(['search', 'group', 'in_stock', 'price_from', 'price_to']))
                        По заданным критериям ничего не найдено.
                    @else
                        Загрузите товары из МойСклад или добавьте вручную.
                    @endif
                </p>
                <a href="{{ route('products.sync') }}" class="btn btn-success btn-lg me-2">
                    <i class="bi bi-cloud-download"></i> Загрузить из МойСклад
                </a>
                <a href="{{ route('products.create') }}" class="btn btn-primary btn-lg">
                    <i class="bi bi-plus-circle"></i> Добавить вручную
                </a>
                @if(request()->anyFilled(['search', 'group', 'in_stock', 'price_from', 'price_to']))
                    <a href="{{ route('products.index') }}" class="btn btn-outline-secondary btn-lg ms-2">
                        <i class="bi bi-x-circle"></i> Сбросить фильтры
                    </a>
                @endif
            </div>
        @endif
    </div>
@endsection

@push('styles')
    <style>
        /* Фикс для дропдауна */
        .dropdown-tree {
            position: relative;
            width: 100%;
        }

        .dropdown-tree .dropdown-menu {
            max-width: 100%;
            min-width: 100%;
            width: auto;
            max-height: 400px;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 0.5rem 0;
            font-size: 0.9rem;
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15);
            border: 1px solid rgba(0,0,0,0.1);
        }

        /* Стили для дерева внутри дропдауна */
        .tree-filter {
            max-width: 100%;
            overflow-x: hidden;
        }

        .tree-filter-item {
            width: 100%;
            max-width: 100%;
        }

        .tree-filter-item .group-link {
            max-width: calc(100% - 24px);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .tree-filter-children {
            width: 100%;
            overflow-x: hidden;
        }

        /* Кнопка дропдауна */
        #groupDropdownBtn {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            padding-right: 2rem;
            position: relative;
        }

        #groupDropdownBtn::after {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
        }

        /* Анимации */
        .tree-filter-children {
            transition: all 0.2s ease;
        }

        .tree-filter-toggle {
            transition: transform 0.2s;
        }

        .tree-filter-toggle:hover {
            background-color: #e9ecef !important;
            border-radius: 4px;
        }

        /* Скроллбар */
        .dropdown-menu::-webkit-scrollbar {
            width: 6px;
        }

        .dropdown-menu::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        .dropdown-menu::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 3px;
        }

        .dropdown-menu::-webkit-scrollbar-thumb:hover {
            background: #555;
        }

        /* Стили для навигационных плиток */
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

        /* Разные цвета для разных плиток */
        .bg-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .bg-success {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }

        .bg-warning {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        }

        .bg-secondary {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }

        /* Анимация появления плиток */
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
