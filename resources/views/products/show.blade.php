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
                    <div class="card-header bg-white">
                        <h4 class="mb-0">{{ $product->name }}</h4>
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
                                    @if($product->old_price)
                                        <tr>
                                            <th>Старая цена:</th>
                                            <td>
                                        <span class="text-muted text-decoration-line-through">
                                            {{ number_format($product->old_price, 2, ',', ' ') }} ₽
                                        </span>
                                                <span class="badge bg-danger ms-2">
                                            -{{ $product->discount_percent }}%
                                        </span>
                                            </td>
                                        </tr>
                                    @endif
                                    <tr>
                                        <th>Количество:</th>
                                        <td>
                                            @if($product->quantity > 0)
                                                <span class="badge bg-success" style="font-size: 1rem;">
                                                {{ $product->quantity }} шт.
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
                            $attributes = json_decode($product->attributes, true) ?? [];
                        @endphp

                        @if(!empty($attributes))
                            <table class="table table-sm">
                                @foreach($attributes as $key => $value)
                                    @if($value)
                                        <tr>
                                            <th>{{ ucfirst($key) }}:</th>
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
