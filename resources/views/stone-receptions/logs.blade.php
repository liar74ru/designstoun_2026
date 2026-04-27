@extends('layouts.app')
@section('title', 'Приёмки камня — журнал логов')

@section('content')
    <div class="container py-3 py-md-4">

        <x-page-header title="📋 Журнал приёмок" :hide-mobile="true">
            <x-slot:actions>
                <a href="{{ route('stone-receptions.create') }}" class="btn btn-success btn-lg px-4">
                    <i class="bi bi-plus-circle"></i> Новая приёмка
                </a>
            </x-slot:actions>
        </x-page-header>

        @include('partials.alerts')

        @include('stone-receptions.partials.mobile-tabs', ['activeTab' => 'logs'])

        {{-- Десктоп: переключатель вида --}}
        <ul class="nav nav-pills mb-3 mb-md-4 d-none d-md-flex">
            <li class="nav-item">
                <a class="nav-link py-1 px-3" href="{{ route('stone-receptions.index') }}">
                    <i class="bi bi-table"></i> По партиям
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active py-1 px-3" href="{{ route('stone-receptions.logs') }}">
                    <i class="bi bi-journal-text"></i> По приёмкам
                </a>
            </li>
        </ul>

        {{-- ═══════════════════════ ФИЛЬТРЫ ═══════════════════════ --}}
        @include('partials.filters', [
            'filterCutters'     => $filterCutters,
            'cutterParam'       => 'cutter_id',
            'filterRawProducts' => $filterRawProducts,
            'rawProductParam'   => 'raw_material_product_id',
            'filterProducts'    => $filterProducts,
            'showStatus'        => false,
            'statusOptions'     => [],
        ])

        {{-- ═══════════════════════ ДАННЫЕ ═══════════════════════ --}}
        @if($logs->count() > 0)

            <div class="card shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
                <span class="fw-semibold text-muted small"><i class="bi bi-list-ul me-1"></i> Записи приёмок</span>
                <span class="text-muted small">Найдено: {{ $logs->total() }}</span>
            </div>
            {{-- ─── ДЕСКТОП: таблица (скрыта на мобильном) ─── --}}
            <div class="d-none d-md-block">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>Дата</th>
                            <th>Пильщик</th>
                            <th>Приёмщик</th>
                            <th>Сырьё</th>
                            <th>Продукция (дельта)</th>
                            <th>Тип</th>
                            <th class="text-end">Действия</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($logs as $log)
                            <tr class="{{ $log->type === 'updated' ? 'table-warning' : '' }}">
                                <td class="text-nowrap">{{ $log->created_at->format('d.m.Y H:i') }}</td>
                                <td><strong>{{ $log->cutter->name ?? '—' }}</strong></td>
                                <td>{{ $log->receiver->name ?? '—' }}</td>
                                <td>
                                    @if($log->rawMaterialBatch)
                                        @php
                                            $rawDelta       = (float) $log->raw_quantity_delta;
                                            $snapshot       = $log->raw_quantity_snapshot !== null ? (float) $log->raw_quantity_snapshot : null;
                                            $batchRemaining = (float) ($log->rawMaterialBatch->remaining_quantity ?? 0);
                                            // delta > 0 = списали сырьё, показываем как отрицательное (красный)
                                            $deltaDisplay   = $rawDelta != 0 ? ($rawDelta > 0 ? '-' : '+') . number_format(abs($rawDelta), 3, '.', '') : '0.000';
                                            $deltaIsConsume = $rawDelta > 0;
                                        @endphp
                                        <a href="{{ route('raw-batches.show', $log->rawMaterialBatch) }}">
                                            {{ $log->rawMaterialBatch->product->name ?? '?' }}
                                        </a>
                                        <br>
                                        <div class="d-flex gap-1 align-items-center mt-1 flex-wrap">
                                            <span title="Было в партии на момент приёмки" style="font-size:.68rem;padding:1px 5px;border-radius:3px;background:#e9ecef;color:#495057;white-space:nowrap">
                                                {{ $snapshot !== null ? number_format($snapshot, 3, '.', '') : '—' }}
                                            </span>
                                            <span title="Изменение сырья в этой приёмке" style="font-size:.68rem;padding:1px 5px;border-radius:3px;background:{{ $deltaIsConsume ? '#f8d7da' : '#d1e7dd' }};color:{{ $deltaIsConsume ? '#842029' : '#0a3622' }};white-space:nowrap">{{ $deltaDisplay }}</span>
                                            <span title="Текущий остаток в партии" style="font-size:.68rem;padding:1px 5px;border-radius:3px;background:{{ $batchRemaining > 0 ? '#cff4fc' : '#fff3cd' }};color:{{ $batchRemaining > 0 ? '#055160' : '#664d03' }};white-space:nowrap">{{ number_format($batchRemaining, 3, '.', '') }}</span>
                                        </div>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @foreach($log->items as $item)
                                        <div class="small {{ !$loop->last ? 'mb-1' : '' }}">
                                            {{ $item->product?->name ?? '?' }}
                                            @php $delta = (float) $item->quantity_delta; @endphp
                                            <span class="fw-semibold {{ $delta >= 0 ? 'text-success' : 'text-danger' }}">
                                                {{ $delta >= 0 ? '+' : '' }}{{ number_format($delta, 3, ',', '.') }}
                                            </span>
                                        </div>
                                    @endforeach
                                </td>
                                <td>
                                    @if($log->type === 'created')
                                        <span class="badge bg-success">Создание</span>
                                    @else
                                        <span class="badge bg-warning text-dark">Правка</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="d-flex gap-1 justify-content-end">
                                        @if($log->stoneReception && $log->stoneReception->status === 'active')
                                            <a href="{{ route('stone-receptions.edit', $log->stone_reception_id) }}"
                                               class="btn btn-sm btn-success" title="Редактировать">
                                                <i class="bi bi-plus-lg"></i>
                                            </a>
                                        @endif
                                        @if($log->stoneReception)
                                            <form action="{{ route('stone-receptions.copy', $log->stone_reception_id) }}"
                                                  method="POST" class="d-inline">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-info" title="Копировать">
                                                    <i class="bi bi-copy"></i>
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

            {{-- ─── МОБИЛЬНЫЙ: карточки (скрыты на десктопе) ─── --}}
            <div class="d-md-none" style="padding:.25rem">
                @foreach($logs as $log)
                    @include('partials.reception-log-card', [
                        'log'             => $log,
                        'showActions'     => true,
                        'showRawDetails'  => true,
                        'showStoreBottom' => false,
                    ])
                @endforeach
            </div>

            {{-- Пагинация --}}
            <div class="d-flex justify-content-between align-items-center p-2 p-md-3 border-top">
                <span class="text-muted small">
                    Показано {{ $logs->firstItem() }}–{{ $logs->lastItem() }} из {{ $logs->total() }}
                </span>
                {{ $logs->links() }}
            </div>

            </div>{{-- /card --}}

        @else
            <div class="text-center py-5">
                <i class="bi bi-journal-x display-1 text-muted"></i>
                <h4 class="text-muted mt-3">Записей не найдено</h4>
            </div>
        @endif

    </div>
@endsection

@push('scripts')
    @vite(['resources/js/product-picker.js'])
@endpush
