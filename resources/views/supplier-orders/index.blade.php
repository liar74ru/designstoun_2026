@extends('layouts.app')

@section('title', 'Поступления сырья')

@section('content')
<style>
    /* Увеличение кнопок действий в таблице на 15% относительно btn-sm */
    .supplier-orders-table .btn-sm {
        font-size: 1rem;
        padding: .29rem .575rem;
    }
</style>
<div class="container py-3">

    <x-page-header
        title="📥 Поступления сырья"
        mobileTitle="Поступления сырья"
        :hide-mobile="true">
        <x-slot name="actions">
            <a href="{{ route('supplier-orders.create') }}" class="btn btn-success btn-lg px-4">
                <i class="bi bi-plus-circle"></i> Поступление сырья
            </a>
        </x-slot>
    </x-page-header>

    {{-- Мобильная кнопка --}}
    <div class="d-md-none mb-2">
        <a href="{{ route('supplier-orders.create') }}" class="btn btn-success w-100">
            <i class="bi bi-plus-circle"></i> Поступление сырья
        </a>
    </div>

    @include('partials.alerts')

    @if($orders->count() > 0)

        {{-- Десктоп --}}
        <div class="d-none d-md-block card shadow-sm supplier-orders-table">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Номер</th>
                            <th>Поставщик</th>
                            <th>Склад</th>
                            <th>Позиции</th>
                            <th>Приёмщик</th>
                            <th>Статус</th>
                            <th>Дата</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($orders as $order)
                        <tr>
                            <td class="fw-semibold">{{ $order->number }}</td>
                            <td>{{ $order->counterparty?->name ?? '—' }}</td>
                            <td class="text-muted small">{{ $order->store?->name ?? '—' }}</td>
                            <td>
                                @foreach($order->items as $item)
                                    <div class="small text-truncate" style="max-width:200px" title="{{ $item->product?->name }}">
                                        {{ $item->product?->name ?? '—' }}
                                        <span class="text-muted">× {{ rtrim(rtrim(number_format($item->quantity, 3), '0'), '.') }} м³</span>
                                    </div>
                                @endforeach
                            </td>
                            <td class="small">{{ $order->receiver?->name ?? '—' }}</td>
                            <td>
                                <span class="badge {{ $order->statusBadgeClass() }}">{{ $order->statusLabel() }}</span>
                                @if($order->sync_error)
                                    <i class="bi bi-exclamation-circle text-danger ms-1" title="{{ $order->sync_error }}"></i>
                                @endif
                            </td>
                            <td class="text-muted small">{{ $order->created_at->format('d.m.Y H:i') }}</td>
                            <td>
                                <div class="d-flex gap-1 flex-wrap">
                                    <a href="{{ route('supplier-orders.show', $order) }}" class="btn btn-sm btn-outline-secondary" title="Просмотр">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    @if($order->status === \App\Models\SupplierOrder::STATUS_NEW)
                                        <form method="POST" action="{{ route('supplier-orders.sync', $order) }}"
                                              class="d-inline"
                                              onsubmit="return confirm('Создать приёмку в МойСклад для поступления №{{ $order->number }}?')">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-primary" title="Создать приёмку в МойСклад">
                                                <i class="bi bi-cloud-upload"></i>
                                            </button>
                                        </form>
                                    @endif
                                    <a href="{{ route('supplier-orders.create', ['copy_from' => $order->id]) }}"
                                       class="btn btn-sm btn-outline-secondary" title="Скопировать поступление">
                                        <i class="bi bi-copy"></i>
                                    </a>
                                    @if($order->isNew())
                                        <a href="{{ route('supplier-orders.edit', $order) }}" class="btn btn-sm btn-outline-secondary" title="Редактировать">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <form method="POST" action="{{ route('supplier-orders.destroy', $order) }}"
                                              class="d-inline"
                                              onsubmit="return confirm('Удалить поступление №{{ $order->number }}? Запись также будет удалена в МойСклад.')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Удалить">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Мобильный --}}
        <div class="d-md-none">
            @foreach($orders as $order)
                @php
                    $firstItem = $order->items->first();
                    $skuColor  = \App\Models\Product::getColorBySku($firstItem?->product?->sku ?? null);
                    $skuBg     = $skuColor === '#FFFFFF' ? '' : 'background:' . $skuColor . '18;';
                    $fmt = fn($v) => rtrim(rtrim(number_format($v, 2), '0'), '.');
                @endphp
                <div class="info-block mb-2" style="border-left:4px solid {{ $skuColor }};border-right:4px solid {{ $skuColor }};{{ $skuBg }}">
                    <div class="info-block-header d-flex justify-content-between align-items-center">
                        <span class="fw-semibold small text-dark">{{ $order->number }}</span>
                        <div class="d-flex align-items-center gap-1">
                            <span class="badge {{ $order->statusBadgeClass() }}">{{ $order->statusLabel() }}</span>
                            @if($order->sync_error)
                                <i class="bi bi-exclamation-circle text-danger" title="{{ $order->sync_error }}"></i>
                            @endif
                        </div>
                    </div>
                    <div class="info-block-body d-flex gap-2 align-items-stretch">
                        {{-- Левая часть: информация --}}
                        <div class="flex-grow-1 min-w-0">
                            <div class="fw-semibold mb-1">{{ $order->counterparty?->name ?? '—' }}</div>

                            {{-- Продукты сразу после контрагента --}}
                            @foreach($order->items as $item)
                                <div class="d-flex align-items-center gap-1 mb-1" style="font-size:.8rem">
                                    <i class="bi bi-box text-muted flex-shrink-0"></i>
                                    <span class="text-truncate">{{ $item->product?->name ?? '—' }}</span>
                                    <span class="text-muted flex-shrink-0">× {{ $fmt($item->quantity) }} м³</span>
                                </div>
                            @endforeach

                            <div class="small text-muted mt-1">
                                <i class="bi bi-building me-1"></i>{{ $order->store?->name ?? '—' }}
                            </div>
                            <div class="small text-muted">
                                <i class="bi bi-calendar me-1"></i>{{ $order->created_at->format('d.m.Y') }}
                            </div>
                        </div>

                        {{-- Правая часть: кнопки --}}
                        <div class="d-flex flex-column gap-1 flex-shrink-0" style="width:88px">
                            @if($order->status === \App\Models\SupplierOrder::STATUS_NEW)
                                <form method="POST" action="{{ route('supplier-orders.sync', $order) }}"
                                      onsubmit="return confirm('Создать приёмку в МойСклад для поступления №{{ $order->number }}?')">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-primary w-100"
                                            style="font-size:.8rem;padding:.23rem .35rem">
                                        <i class="bi bi-cloud-upload"></i> Приёмка
                                    </button>
                                </form>
                            @endif
                            <a href="{{ route('supplier-orders.show', $order) }}"
                               class="btn btn-outline-secondary w-100"
                               style="font-size:.8rem;padding:.23rem .35rem">
                                <i class="bi bi-eye"></i> Просм
                            </a>
                            <a href="{{ route('supplier-orders.create', ['copy_from' => $order->id]) }}"
                               class="btn btn-outline-secondary w-100"
                               style="font-size:.8rem;padding:.23rem .35rem">
                                <i class="bi bi-copy"></i> Копия
                            </a>
                            @if($order->isNew())
                                <a href="{{ route('supplier-orders.edit', $order) }}"
                                   class="btn btn-outline-secondary w-100"
                                   style="font-size:.8rem;padding:.23rem .35rem">
                                    <i class="bi bi-pencil"></i> Изменить
                                </a>
                                <form method="POST" action="{{ route('supplier-orders.destroy', $order) }}"
                                      onsubmit="return confirm('Удалить поступление №{{ $order->number }}? Запись также будет удалена в МойСклад.')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger w-100"
                                            style="font-size:.8rem;padding:.23rem .35rem">
                                        <i class="bi bi-trash"></i> Удалить
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="d-flex justify-content-center mt-3">
            {{ $orders->withQueryString()->links() }}
        </div>

    @else
        <div class="text-center py-5">
            <i class="bi bi-box-seam display-1 text-muted"></i>
            <h3 class="text-muted mt-3">Поступлений нет</h3>
            <p class="mb-4">Создайте первое поступление сырья</p>
            <a href="{{ route('supplier-orders.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Поступление сырья
            </a>
        </div>
    @endif

</div>
@endsection
