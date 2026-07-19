@extends('layouts.app')
@section('title', 'Цех #' . $workshop->id)

@section('content')
<div class="container py-3 py-md-4" style="max-width:1100px">

    <x-page-header title="Цех #{{ $workshop->id }}" :back-url="$backUrl" mobileTitle="Цех" />

    @include('partials.alerts')

    <div class="row g-3">
        {{-- Левая колонка: статус, участники, тара, МойСклад --}}
        <div class="col-12 col-lg-5">

            {{-- Статус + действия --}}
            <div class="info-block card shadow-sm mb-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
                    <span class="small fw-semibold text-muted"><i class="bi bi-flag me-1"></i> Статус</span>
                    @if($workshop->status === 'active')
                        <span class="badge bg-success">Активна</span>
                    @elseif($workshop->status === 'completed')
                        <span class="badge bg-warning text-dark">Закрыта</span>
                    @else
                        <span class="badge bg-danger">Ошибка</span>
                    @endif
                </div>
                <div class="card-body p-2">
                    <div class="d-flex flex-wrap gap-1">
                        @if($workshop->status === 'active')
                            <a href="{{ route('workshops.edit', $workshop) }}" class="btn btn-sm btn-success">
                                <i class="bi bi-pencil"></i> Редактировать
                            </a>
                            <form method="POST" action="{{ route('workshops.mark-completed', $workshop) }}" class="d-inline" onsubmit="return confirm('Закрыть операцию?')">
                                @csrf @method('PATCH')
                                <button type="submit" class="btn btn-sm btn-warning">
                                    <i class="bi bi-check2-circle"></i> Закрыть
                                </button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('workshops.reset-status', $workshop) }}" class="d-inline" onsubmit="return confirm('Сбросить статус?')">
                                @csrf @method('PATCH')
                                <button type="submit" class="btn btn-sm btn-outline-warning">
                                    <i class="bi bi-arrow-counterclockwise"></i> Активировать
                                </button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('workshops.copy', $workshop) }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-info">
                                <i class="bi bi-copy"></i> Копировать
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            {{-- Участники --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white py-2">
                    <span class="small fw-semibold text-muted"><i class="bi bi-people me-1"></i> Участники</span>
                </div>
                <div class="card-body p-2 small">
                    <div><strong>Работник:</strong> {{ $workshop->packer->name ?? '—' }}</div>
                    <div><strong>Приёмщик:</strong> {{ $workshop->receiver->name ?? '—' }}</div>
                    <div><strong>Склад:</strong> {{ $workshop->store->name ?? '—' }}</div>
                    <div><strong>Создана:</strong> {{ $workshop->created_at->format('d.m.Y H:i') }}</div>
                </div>
            </div>

            {{-- Затраты на производство --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white py-2">
                    <span class="small fw-semibold text-muted"><i class="bi bi-cash-coin me-1"></i> Затраты на производство</span>
                </div>
                <div class="card-body p-2 small">
                    @if($workshop->manual_processing_sum !== null)
                        <span class="badge bg-secondary">{{ number_format((float) $workshop->manual_processing_sum, 2) }} ₽/ед</span>
                        <span class="text-muted">(ручной ввод)</span>
                    @else
                        <span class="text-muted">Автоматически (по зарплате работника)</span>
                    @endif
                </div>
            </div>

            {{-- Примечание --}}
            @if($workshop->notes)
                <div class="card shadow-sm mb-3">
                    <div class="card-header bg-white py-2">
                        <span class="small fw-semibold text-muted"><i class="bi bi-chat-text me-1"></i> Примечание</span>
                    </div>
                    <div class="card-body p-2 small">{{ $workshop->notes }}</div>
                </div>
            @endif

            {{-- МойСклад --}}
            <x-moysklad-sync-status
                :model="$workshop"
                :sync-route="route('workshops.sync', $workshop)" />
        </div>

        {{-- Правая колонка: позиции + лог --}}
        <div class="col-12 col-lg-7">

            @php
                $rawItems     = $workshop->items->where('role', \App\Models\WorkshopItem::ROLE_RAW);
                $packageItems = $workshop->items->where('role', \App\Models\WorkshopItem::ROLE_PACKAGE);
                $productItems = $workshop->items->where('role', \App\Models\WorkshopItem::ROLE_PRODUCT);
            @endphp

            {{-- Сырьё --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                    <span class="small fw-semibold text-muted"><i class="bi bi-box me-1"></i> Сырьё</span>
                    <span class="text-muted small">Итого: <strong>{{ number_format($rawItems->sum('quantity'), 3) }}</strong></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Товар</th>
                                <th class="text-end">Кол-во</th>
                                <th class="text-end">Зарплата</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($rawItems as $item)
                            <tr>
                                <td>
                                    <div>{{ $item->product->name }}</div>
                                    <small class="text-muted">{{ $item->product->sku }}</small>
                                </td>
                                <td class="text-end">{{ number_format($item->quantity, 3) }}</td>
                                <td class="text-end">{{ number_format($item->calculateWorkerPay(), 2) }} ₽</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-muted text-center">—</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Упаковка --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white py-2">
                    <span class="small fw-semibold text-muted"><i class="bi bi-box-seam me-1"></i> Упаковка</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Тара</th>
                                <th class="text-end">Кол-во</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($packageItems as $item)
                            <tr>
                                <td>
                                    <div>{{ $item->product->name }}</div>
                                    <small class="text-muted">{{ $item->product->sku }}</small>
                                </td>
                                <td class="text-end">{{ number_format($item->quantity, 3) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="text-muted text-center">—</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Продукт --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                    <span class="small fw-semibold text-muted"><i class="bi bi-check2-circle me-1"></i> Продукт</span>
                    <span class="text-muted small">Итого: <strong>{{ number_format($productItems->sum('quantity'), 3) }}</strong></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Товар</th>
                                <th class="text-end">Кол-во</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($productItems as $item)
                            <tr>
                                <td>
                                    <div>{{ $item->product->name }}</div>
                                    <small class="text-muted">{{ $item->product->sku }}</small>
                                </td>
                                <td class="text-end">{{ number_format($item->quantity, 3) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="text-muted text-center">—</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Лог изменений --}}
            @if($workshop->workshopLogs->isNotEmpty())
                <div class="card shadow-sm mb-3">
                    <div class="card-header bg-white py-2">
                        <span class="small fw-semibold text-muted"><i class="bi bi-clock-history me-1"></i> История изменений</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Дата</th>
                                    <th>Тип</th>
                                    <th>Продукт Δ</th>
                                    <th>Позиции Δ</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach($workshop->workshopLogs as $log)
                                <tr class="{{ $log->type === 'updated' ? 'table-warning' : '' }}">
                                    <td class="text-nowrap">{{ $log->created_at->format('d.m H:i') }}</td>
                                    <td>
                                        @if($log->type === 'created')
                                            <span class="badge bg-success">Создание</span>
                                        @else
                                            <span class="badge bg-warning text-dark">Правка</span>
                                        @endif
                                    </td>
                                    <td>
                                        @php $d = (float) $log->package_quantity_delta; @endphp
                                        @if(abs($d) > 0.0001)
                                            <span class="fw-semibold {{ $d >= 0 ? 'text-success' : 'text-danger' }}">{{ $d >= 0 ? '+' : '' }}{{ number_format($d, 0) }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>
                                        @php $roleLabels = ['raw' => 'Сырьё', 'package' => 'Упаковка', 'product' => 'Продукт']; @endphp
                                        @foreach($log->items as $i)
                                            @php $delta = (float) $i->quantity_delta; @endphp
                                            <div class="small">
                                                <span class="text-muted">[{{ $roleLabels[$i->role] ?? '' }}]</span>
                                                {{ $i->product?->name ?? '?' }}:
                                                <span class="fw-semibold {{ $delta >= 0 ? 'text-success' : 'text-danger' }}">{{ $delta >= 0 ? '+' : '' }}{{ number_format($delta, 3) }}</span>
                                            </div>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
