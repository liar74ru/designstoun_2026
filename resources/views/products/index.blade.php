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
                   onclick="return confirm('Загрузить/обновить товары из МойСклад?')">
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

        <!-- Статистика -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h5 class="card-title">Всего товаров</h5>
                        <h2>{{ $products->total() }}</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h5 class="card-title">В наличии</h5>
                        <h2>{{ $products->sum('quantity') }} шт.</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h5 class="card-title">Общая стоимость</h5>
                        <h2>{{ number_format($products->sum('price'), 0, ',', ' ') }} ₽</h2>
                    </div>
                </div>
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
                                <a href="{{ route('products.index', ['sort' => 'name', 'direction' => $sortField == 'name' && $sortDirection == 'asc' ? 'desc' : 'asc']) }}"
                                   class="text-decoration-none text-dark">
                                    Название
                                    @if($sortField == 'name')
                                        <i class="bi bi-arrow-{{ $sortDirection == 'asc' ? 'up' : 'down' }}"></i>
                                    @endif
                                </a>
                            </th>
                            <th>
                                <a href="{{ route('products.index', ['sort' => 'sku', 'direction' => $sortField == 'sku' && $sortDirection == 'asc' ? 'desc' : 'asc']) }}"
                                   class="text-decoration-none text-dark">
                                    Артикул
                                    @if($sortField == 'sku')
                                        <i class="bi bi-arrow-{{ $sortDirection == 'asc' ? 'up' : 'down' }}"></i>
                                    @endif
                                </a>
                            </th>
                            <th>
                                <a href="{{ route('products.index', ['sort' => 'price', 'direction' => $sortField == 'price' && $sortDirection == 'asc' ? 'desc' : 'asc']) }}"
                                   class="text-decoration-none text-dark">
                                    Цена
                                    @if($sortField == 'price')
                                        <i class="bi bi-arrow-{{ $sortDirection == 'asc' ? 'up' : 'down' }}"></i>
                                    @endif
                                </a>
                            </th>
                            <th>
                                <a href="{{ route('products.index', ['sort' => 'quantity', 'direction' => $sortField == 'quantity' && $sortDirection == 'asc' ? 'desc' : 'asc']) }}"
                                   class="text-decoration-none text-dark">
                                    Остаток
                                    @if($sortField == 'quantity')
                                        <i class="bi bi-arrow-{{ $sortDirection == 'asc' ? 'up' : 'down' }}"></i>
                                    @endif
                                </a>
                            </th>
                            <th>Статус</th>
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
                                    <span class="badge bg-secondary">{{ $product->sku ?? '—' }}</span>
                                </td>
                                <td>
                                    <span class="fw-bold">{{ number_format($product->price, 2, ',', ' ') }} ₽</span>
                                    @if($product->old_price)
                                        <br>
                                        <small class="text-muted text-decoration-line-through">
                                            {{ number_format($product->old_price, 2, ',', ' ') }} ₽
                                        </small>
                                        <span class="badge bg-danger">-{{ $product->discount_percent }}%</span>
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
                                    @if($product->is_active)
                                        <span class="badge bg-success">Активен</span>
                                    @else
                                        <span class="badge bg-secondary">Неактивен</span>
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
                    {{ $products->links() }}
                </div>
            </div>
        @else
            <div class="text-center py-5">
                <div class="display-1 text-muted mb-4">📦</div>
                <h3 class="text-muted mb-3">Товары не найдены</h3>
                <p class="mb-4">Загрузите товары из МойСклад или добавьте вручную</p>
                <a href="{{ route('products.sync') }}" class="btn btn-success btn-lg me-2">
                    <i class="bi bi-cloud-download"></i> Загрузить из МойСклад
                </a>
                <a href="{{ route('products.create') }}" class="btn btn-primary btn-lg">
                    <i class="bi bi-plus-circle"></i> Добавить вручную
                </a>
            </div>
        @endif
    </div>
@endsection
