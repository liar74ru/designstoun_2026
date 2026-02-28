@extends('layouts.app')

@section('title', $product->name)

@section('content')
    <div class="container py-4">
        <!-- Навигация -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <a href="{{ route('products.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> К списку товаров
                </a>
            </div>
            <div class="btn-group">
                <a href="{{ route('products.refresh', $product->moysklad_id) }}"
                   class="btn btn-warning"
                   onclick="return confirm('Обновить данные товара из МойСклад?')">
                    <i class="bi bi-arrow-repeat"></i> Обновить
                </a>
                <form action="{{ route('products.destroy', $product->moysklad_id) }}"
                      method="POST"
                      class="d-inline"
                      onsubmit="return confirm('Вы уверены, что хотите удалить товар "{{ $product->name }}" из локальной базы?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-trash"></i> Удалить
                </button>
                </form>
            </div>
        </div>

        <!-- Информация о товаре -->
        <div class="row">
            <div class="col-md-8">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">{{ $product->name }}</h4>
                        <span class="badge bg-info" style="font-size: 1rem;">
                            Общий остаток: {{ number_format($product->quantity, 2, ',', ' ') }}
                            @if($product->stocks->first() && $product->stocks->first()->store)
                                {{ $product->stocks->first()->store->uom ?? 'шт' }}
                            @else
                                шт
                            @endif
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h5 class="text-muted mb-3">Основная информация</h5>
                                <table class="table table-sm">
                                    <tr>
                                        <th style="width: 150px;">Артикул:</th>
                                        <td><span class="badge bg-secondary">{{ $product->sku ?? '—' }}</span></td>
                                    </tr>
                                    <tr>
                                        <th>Код:</th>
                                        <td><span class="badge bg-secondary">{{ $product->code ?? '—' }}</span></td>
                                    </tr>
                                    <tr>
                                        <th>ID в МойСклад:</th>
                                        <td><small class="text-muted">{{ $product->moysklad_id }}</small></td>
                                    </tr>
                                    <tr>
                                        <th>Статус:</th>
                                        <td>
                                            @if($product->is_active)
                                                <span class="badge bg-success">Активен</span>
                                            @else
                                                <span class="badge bg-secondary">Неактивен</span>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Дата добавления:</th>
                                        <td>{{ $product->created_at->format('d.m.Y H:i') }}</td>
                                    </tr>
                                    <tr>
                                        <th>Последнее обновление:</th>
                                        <td>{{ $product->updated_at->format('d.m.Y H:i') }}</td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h5 class="text-muted mb-3">Цена и наличие</h5>
                                <table class="table table-sm">
                                    <tr>
                                        <th style="width: 150px;">Цена:</th>
                                        <td>
                                        <span class="h4 text-primary">
                                            {{ number_format($product->price, 2, ',', ' ') }} ₽
                                        </span>
                                        </td>
                                    </tr>
                                    @if($product->old_price && $product->old_price > 0)
                                        <tr>
                                            <th>Старая цена:</th>
                                            <td>
                                                <span class="text-muted text-decoration-line-through">
                                                    {{ number_format($product->old_price, 2, ',', ' ') }} ₽
                                                </span>
                                                @php
                                                    $discountPercent = round((($product->old_price - $product->price) / $product->old_price) * 100);
                                                @endphp
                                                @if($discountPercent > 0)
                                                    <span class="badge bg-danger ms-2">
                                                        -{{ $discountPercent }}%
                                                    </span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endif
                                    <tr>
                                        <th>Общее количество:</th>
                                        <td>
                                            @if($product->quantity > 0)
                                                <span class="badge bg-success" style="font-size: 1rem;">
                                                    {{ number_format($product->quantity, 3, ',', ' ') }}
                                                    @if($product->stocks->first() && $product->stocks->first()->store)
                                                        {{ $product->stocks->first()->store->uom ?? 'шт' }}
                                                    @else
                                                        шт
                                                    @endif
                                                </span>
                                            @else
                                                <span class="badge bg-danger" style="font-size: 1rem;">
                                                    Нет в наличии
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        @if($product->description)
                            <div class="mb-4">
                                <h5 class="text-muted mb-3">Описание</h5>
                                <div class="p-3 bg-light rounded">
                                    {{ $product->description }}
                                </div>
                            </div>
                        @endif

                        <!-- Блок остатков по складам -->
                        <div class="mt-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="text-muted mb-0">Остатки по складам</h5>
                                <form action="{{ route('products.stocks.sync', $product->moysklad_id) }}"
                                      method="POST"
                                      class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-primary"
                                            onclick="return confirm('Обновить остатки по складам из МойСклад?')">
                                        <i class="bi bi-arrow-repeat"></i> Синхронизировать остатки
                                    </button>
                                </form>
                            </div>

                            @if($product->stocks && $product->stocks->count() > 0)
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead class="table-light">
                                        <tr>
                                            <th>Склад</th>
                                            <th class="text-center">Количество</th>
                                            <th class="text-center">Резерв</th>
                                            <th class="text-center">В пути</th>
                                            <th class="text-center">Доступно</th>
                                            <th>Последнее обновление</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        {{-- ОТЛАДКА --}}
                                        <div class="alert alert-info">
                                            <h4>Отладка:</h4>
                                            <p>1. $product существует: {{ $product ? 'да' : 'нет' }}</p>
                                            <p>2. Метод stocks(): {{ method_exists($product, 'stocks') ? 'да' : 'нет' }}</p>
                                            <p>3. stocks загружены: {{ $product->relationLoaded('stocks') ? 'да' : 'нет' }}</p>
                                            <p>4. stocks количество: {{ $product->stocks ? $product->stocks->count() : 'null' }}</p>
                                            @if($product->stocks && $product->stocks->count() > 0)
                                                <p>5. Первый stock quantity: {{ $product->stocks->first()->quantity }}</p>
                                                <p>6. Первый stock store_id: {{ $product->stocks->first()->store_id }}</p>
                                                <p>6. id связь с продуктом: {{ $product->stocks->first()->product_id }}</p>
                                                <p>6. id связь с продуктом: {{ $product->id }}
                                                </p> <p>6. id связь с продуктом: {{ $product->moysklad_id }}</p>
                                            @endif
                                            <p>7. SQL лог: {{ \Illuminate\Support\Facades\DB::getQueryLog() ? json_encode(\Illuminate\Support\Facades\DB::getQueryLog()) : 'пусто' }}</p>
                                        </div>
                                        @foreach($product->stocks as $stock)
                                            <tr>
                                                <td>
                                                    <strong>{{ $stock->store->name ?? 'Неизвестный склад' }}</strong>
                                                    @if($stock->store && $stock->store->path_name)
                                                        <br>
                                                        <small class="text-muted">{{ $stock->store->path_name }}</small>
                                                    @endif
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-primary">{{ number_format($stock->quantity, 3, ',', ' ') }}</span>
                                                </td>
                                                <td class="text-center">
                                                    @if($stock->reserved > 0)
                                                        <span class="badge bg-warning text-dark">{{ number_format($stock->reserved, 3, ',', ' ') }}</span>
                                                    @else
                                                        <span class="text-muted">0</span>
                                                    @endif
                                                </td>
                                                <td class="text-center">
                                                    @if($stock->in_transit > 0)
                                                        <span class="badge bg-info">{{ number_format($stock->in_transit, 3, ',', ' ') }}</span>
                                                    @else
                                                        <span class="text-muted">0</span>
                                                    @endif
                                                </td>
                                                <td class="text-center">
                                                    @if($stock->available > 0)
                                                        <span class="badge bg-success">{{ number_format($stock->available, 3, ',', ' ') }}</span>
                                                    @else
                                                        <span class="text-muted">0</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        {{ $stock->updated_at->format('d.m.Y H:i') }}
                                                    </small>
                                                </td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                        <tfoot class="table-light">
                                        <tr>
                                            <th>Итого:</th>
                                            <th class="text-center">{{ number_format($product->stocks->sum('quantity'), 3, ',', ' ') }}</th>
                                            <th class="text-center">{{ number_format($product->stocks->sum('reserved'), 3, ',', ' ') }}</th>
                                            <th class="text-center">{{ number_format($product->stocks->sum('in_transit'), 3, ',', ' ') }}</th>
                                            <th class="text-center">{{ number_format($product->stocks->sum('available'), 3, ',', ' ') }}</th>
                                            <th></th>
                                        </tr>
                                        </tfoot>
                                    </table>
                                </div>

                                @if($product->stocks->where('quantity', 0)->count() > 0)
                                    <div class="mt-2">
                                        <small class="text-muted">
                                            * Показаны только склады с ненулевым остатком.
                                            Складов с нулевым остатком: {{ $product->stocks->where('quantity', 0)->count() }}
                                        </small>
                                    </div>
                                @endif
                            @else
                                <div class="alert alert-light border text-center py-4">
                                    <i class="bi bi-box-seam d-block mb-2" style="font-size: 2rem;"></i>
                                    <p class="mb-0">Нет данных об остатках по складам</p>
                                    <small class="text-muted">Нажмите "Синхронизировать остатки" для загрузки данных</small>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <!-- Дополнительные атрибуты -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">Дополнительные атрибуты</h5>
                    </div>
                    <div class="card-body">
                        @php
                            $attributes = is_string($product->attributes) ? json_decode($product->attributes, true) : ($product->attributes ?? []);
                        @endphp

                        @if(!empty($attributes))
                            <table class="table table-sm">
                                @foreach($attributes as $key => $value)
                                    @if($value && !in_array($key, ['meta', 'zones', 'slots']))
                                        <tr>
                                            <th style="width: 100px;">{{ ucfirst(str_replace('_', ' ', $key)) }}:</th>
                                            <td>{{ $value }}</td>
                                        </tr>
                                    @endif
                                @endforeach
                            </table>
                        @else
                            <p class="text-muted mb-0">Нет дополнительных атрибутов</p>
                        @endif
                    </div>
                </div>

                <!-- Краткая статистика -->
                <div class="card shadow-sm bg-light">
                    <div class="card-body">
                        <h5 class="mb-3">Быстрые действия</h5>
                        <div class="d-grid gap-2">
                            <a href="{{ route('products.refresh', $product->moysklad_id) }}"
                               class="btn btn-warning"
                               onclick="return confirm('Обновить данные товара из МойСклад?')">
                                <i class="bi bi-arrow-repeat"></i> Обновить из МойСклад
                            </a>
                            <form action="{{ route('products.stocks.sync', $product->moysklad_id) }}"
                                  method="POST">
                                @csrf
                                <button type="submit" class="btn btn-outline-success w-100"
                                        onclick="return confirm('Синхронизировать остатки по складам?')">
                                    <i class="bi bi-box-seam"></i> Синхронизировать остатки
                                </button>
                            </form>
                            <form action="{{ route('products.stocks.sync-all') }}"
                                  method="POST">
                                @csrf
                                <button type="submit" class="btn btn-outline-primary w-100"
                                        onclick="return confirm('Обновить остатки ВСЕХ товаров?')">
                                    <i class="bi bi-arrow-repeat"></i> Обновить все остатки
                                </button>
                            </form>
                            <form action="{{ route('products.destroy', $product->moysklad_id) }}"
                                  method="POST"
                                  onsubmit="return confirm('Вы уверены?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger w-100">
                                    <i class="bi bi-trash"></i> Удалить из базы
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
