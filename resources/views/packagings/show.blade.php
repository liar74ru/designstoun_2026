@extends('layouts.app')
@section('title', 'Упаковка #' . $packaging->id)

@section('content')
<div class="container py-3 py-md-4" style="max-width:1100px">

    <x-page-header title="Упаковка #{{ $packaging->id }}" :back-url="$backUrl" mobileTitle="Упаковка" />

    @include('partials.alerts')

    <div class="row g-3">
        {{-- Левая колонка: статус, участники, тара, МойСклад --}}
        <div class="col-12 col-lg-5">

            {{-- Статус + действия --}}
            <div class="info-block card shadow-sm mb-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
                    <span class="small fw-semibold text-muted"><i class="bi bi-flag me-1"></i> Статус</span>
                    @if($packaging->status === 'active')
                        <span class="badge bg-success">Активна</span>
                    @elseif($packaging->status === 'completed')
                        <span class="badge bg-warning text-dark">Закрыта</span>
                    @else
                        <span class="badge bg-danger">Ошибка</span>
                    @endif
                </div>
                <div class="card-body p-2">
                    <div class="d-flex flex-wrap gap-1">
                        @if($packaging->status === 'active')
                            <a href="{{ route('packagings.edit', $packaging) }}" class="btn btn-sm btn-success">
                                <i class="bi bi-pencil"></i> Редактировать
                            </a>
                            <form method="POST" action="{{ route('packagings.mark-completed', $packaging) }}" class="d-inline" onsubmit="return confirm('Закрыть упаковку?')">
                                @csrf @method('PATCH')
                                <button type="submit" class="btn btn-sm btn-warning">
                                    <i class="bi bi-check2-circle"></i> Закрыть
                                </button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('packagings.reset-status', $packaging) }}" class="d-inline" onsubmit="return confirm('Сбросить статус?')">
                                @csrf @method('PATCH')
                                <button type="submit" class="btn btn-sm btn-outline-warning">
                                    <i class="bi bi-arrow-counterclockwise"></i> Активировать
                                </button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('packagings.copy', $packaging) }}" class="d-inline">
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
                    <div><strong>Упаковщик:</strong> {{ $packaging->packer->name ?? '—' }}</div>
                    <div><strong>Приёмщик:</strong> {{ $packaging->receiver->name ?? '—' }}</div>
                    <div><strong>Склад:</strong> {{ $packaging->store->name ?? '—' }}</div>
                    <div><strong>Создана:</strong> {{ $packaging->created_at->format('d.m.Y H:i') }}</div>
                </div>
            </div>

            {{-- Тара --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white py-2">
                    <span class="small fw-semibold text-muted"><i class="bi bi-box-seam me-1"></i> Упаковка (тара)</span>
                </div>
                <div class="card-body p-2 small">
                    @if($packaging->packageProduct)
                        <div><strong>{{ $packaging->packageProduct->name }}</strong></div>
                        <div class="text-muted">{{ $packaging->packageProduct->sku }}</div>
                        <div class="mt-1">Количество: <span class="badge bg-warning text-dark">{{ number_format((float) $packaging->package_quantity, 0) }} шт</span></div>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </div>
            </div>

            {{-- Примечание --}}
            @if($packaging->notes)
                <div class="card shadow-sm mb-3">
                    <div class="card-header bg-white py-2">
                        <span class="small fw-semibold text-muted"><i class="bi bi-chat-text me-1"></i> Примечание</span>
                    </div>
                    <div class="card-body p-2 small">{{ $packaging->notes }}</div>
                </div>
            @endif

            {{-- МойСклад --}}
            <x-moysklad-sync-status
                :model="$packaging"
                :sync-route="route('packagings.sync', $packaging)" />
        </div>

        {{-- Правая колонка: позиции + лог --}}
        <div class="col-12 col-lg-7">

            {{-- Упакованные продукты --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                    <span class="small fw-semibold text-muted"><i class="bi bi-box me-1"></i> Упакованные продукты</span>
                    <span class="text-muted small">Итого: <strong>{{ number_format($packaging->total_quantity, 3) }}</strong></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Продукт</th>
                                <th class="text-end">Кол-во</th>
                                <th class="text-end">Зарплата</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($packaging->items as $item)
                            <tr>
                                <td>
                                    <div>{{ $item->product->name }}</div>
                                    <small class="text-muted">{{ $item->product->sku }}</small>
                                    @if($item->is_undercut)
                                        <span class="badge bg-warning text-dark ms-1">подкол 80%</span>
                                    @endif
                                </td>
                                <td class="text-end">{{ number_format($item->quantity, 3) }}</td>
                                <td class="text-end">{{ number_format($item->calculateWorkerPay(), 2) }} ₽</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Лог изменений --}}
            @if($packaging->packagingLogs->isNotEmpty())
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
                                    <th>Тара Δ</th>
                                    <th>Продукция Δ</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach($packaging->packagingLogs as $log)
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
                                        @foreach($log->items as $i)
                                            @php $delta = (float) $i->quantity_delta; @endphp
                                            <div class="small">
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
