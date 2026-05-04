@extends('layouts.app')
@section('title', 'Приёмки камня')

@section('content')
    <div class="container py-3 py-md-4">

        <x-page-header title="📦 Приёмки камня" :hide-mobile="true">
            <x-slot:actions>
                <a href="{{ route('stone-receptions.create') }}" class="btn btn-success btn-lg px-4">
                    <i class="bi bi-plus-circle"></i> Новая приёмка
                </a>
            </x-slot:actions>
        </x-page-header>

        @include('partials.alerts')

        {{-- Переключатель вкладок --}}
        <div class="d-flex flex-column flex-md-row align-items-stretch align-items-md-center gap-2 mb-3 mb-md-4">
            <div class="w-100 w-md-auto"
                 style="display:flex;gap:.4rem;background:#e9ecef;padding:4px;border-radius:.5rem">
                <button id="view-btn-batches" type="button"
                        style="flex:1;border:none;padding:.3rem .9rem;border-radius:.35rem;cursor:pointer;font-size:.92rem;transition:all .15s">
                    <i class="bi bi-table"></i> По партиям
                </button>
                <button id="view-btn-logs" type="button"
                        style="flex:1;border:none;padding:.3rem .9rem;border-radius:.35rem;cursor:pointer;font-size:.92rem;transition:all .15s">
                    <i class="bi bi-journal-text"></i> По приёмкам
                </button>
            </div>
            <a href="{{ route('stone-receptions.create') }}" class="btn btn-success d-md-none w-100">
                <i class="bi bi-plus-circle"></i> Новая приёмка
            </a>
        </div>

        {{-- ═══════════════════════ ФИЛЬТРЫ ═══════════════════════ --}}
        @include('partials.filters', [
            'filterCutters'      => $filterCutters,
            'cutterParam'        => 'cutter_id',
            'filterRawProducts'  => $filterRawProducts,
            'rawProductParam'    => 'raw_product_id',
            'filterProducts'     => $filterProducts,
            'showStatus'         => 'multi',
            'statusOptions'      => ['active' => 'Активна', 'completed' => 'Завершена', 'error' => 'Ошибка'],
            'statusDefaults'     => ['active', 'error'],
            'showSyncStatus'     => true,
            'syncStatusOptions'  => ['synced' => 'Синхронизирована', 'not_synced' => 'Не синхр.'],
            'filterDepartments'  => $filterDepartments,
            'departmentDefaults' => $departmentDefaults,
        ])

        {{-- ═══════════════════════ ПО ПАРТИЯМ ═══════════════════════ --}}
        <div id="view-batches">
            @if($receptions->count() > 0)

                <div class="card shadow-sm">
                    <div class="card-header bg-white d-flex justify-content-end align-items-center py-2">
                        <span class="text-muted small">Найдено: {{ $receptions->total() }}</span>
                    </div>

                    {{-- ─── ДЕСКТОП: таблица ─── --}}
                    <div class="d-none d-md-block">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Дата</th>
                                    <th>Продукция</th>
                                    <th>Итого</th>
                                    <th>Сырьё</th>
                                    <th>Расход</th>
                                    <th>Приёмщик</th>
                                    <th>Пильщик</th>
                                    <th>Склад</th>
                                    <th>Отдел</th>
                                    <th>Статус</th>
                                    <th class="text-end">Действия</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($receptions as $reception)
                                    <tr class="{{ $reception->status == 'processed' ? 'table-success' : ($reception->status == 'completed' ? 'table-warning' : ($reception->status == 'error' ? 'table-danger' : '')) }}">
                                        <td>{{ $reception->id }}</td>
                                        <td class="text-nowrap">{{ $reception->created_at->format('d.m.Y H:i') }}</td>
                                        <td>
                                            @foreach($reception->items as $item)
                                                <div class="{{ !$loop->last ? 'mb-1 pb-1 border-bottom' : '' }}">
                                                    <strong>{{ $item->product->name }}</strong><br>
                                                    <small class="text-muted">{{ $item->product->sku }}</small>
                                                    <span class="badge bg-info ms-1">{{ number_format($item->quantity, 3) }} м²</span>
                                                </div>
                                            @endforeach
                                        </td>
                                        <td><span class="badge bg-primary">{{ number_format($reception->total_quantity, 3) }} м²</span></td>
                                        <td>
                                            @if($reception->rawMaterialBatch)
                                                @php
                                                    $bInit = (float) ($reception->rawMaterialBatch->initial_quantity ?? 0);
                                                    $bRem  = (float) ($reception->rawMaterialBatch->remaining_quantity ?? 0);
                                                @endphp
                                                <a href="{{ route('raw-batches.show', $reception->rawMaterialBatch) }}">
                                                    {{ $reception->rawMaterialBatch->product->name }}
                                                </a>
                                                <br>
                                                <div class="d-flex gap-1 mt-1">
                                                    <span title="Всего в партии" style="font-size:.68rem;padding:1px 5px;border-radius:3px;background:#e9ecef;color:#495057;white-space:nowrap">{{ number_format($bInit, 3, '.', '') }}</span>
                                                    <span title="Доступно в партии" style="font-size:.68rem;padding:1px 5px;border-radius:3px;background:{{ $bRem > 0 ? '#cff4fc' : '#fff3cd' }};color:{{ $bRem > 0 ? '#055160' : '#664d03' }};white-space:nowrap">{{ number_format($bRem, 3, '.', '') }}</span>
                                                </div>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td><span class="badge bg-warning text-dark">{{ number_format($reception->raw_quantity_used, 3) }} м³</span></td>
                                        <td>{{ $reception->receiver->name ?? '—' }}</td>
                                        <td>{{ $reception->cutter->name ?? '—' }}</td>
                                        <td>{{ $reception->store->name ?? '—' }}</td>
                                        <td class="small text-muted">{{ $reception->department?->name ?? '—' }}</td>
                                        <td>
                                            @if($reception->status == 'active')
                                                <span class="badge bg-success">Активна</span>
                                            @elseif($reception->status == 'completed')
                                                <span class="badge bg-warning text-dark">Завершена</span>
                                            @elseif($reception->status == 'processed')
                                                <span class="badge bg-secondary">Обработана</span>
                                                @if($reception->moysklad_processing_id)
                                                    <br><small class="text-muted">{{ substr($reception->moysklad_processing_id, 0, 8) }}…</small>
                                                @endif
                                            @elseif($reception->status == 'error')
                                                <span class="badge bg-danger">Ошибка</span>
                                            @endif
                                            @if($reception->moysklad_sync_status && !$reception->isSynced())
                                                <br><span class="badge {{ $reception->syncStatusBadgeClass() }} mt-1">{{ $reception->syncStatusLabel() }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1 justify-content-end">
                                                @if($reception->status == 'active')
                                                    <a href="{{ route('stone-receptions.edit', $reception) }}"
                                                       class="btn btn-sm btn-success" title="Редактировать">
                                                        <i class="bi bi-plus-lg"></i>
                                                    </a>
                                                @endif
                                                @if($reception->status == 'active')
                                                    <form method="POST" action="{{ route('stone-receptions.mark-completed', $reception) }}" class="d-inline"
                                                          onsubmit="return confirm('Завершить приёмку?')">
                                                        @csrf
                                                        @method('PATCH')
                                                        <button type="submit" class="btn btn-sm btn-warning" title="Завершить приёмку">
                                                            <i class="bi bi-check2-circle"></i>
                                                        </button>
                                                    </form>
                                                @endif
                                                <form action="{{ route('stone-receptions.copy', $reception) }}"
                                                      method="POST" class="d-inline">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-outline-info" title="Копировать">
                                                        <i class="bi bi-copy"></i>
                                                    </button>
                                                </form>
                                                <a href="{{ route('stone-receptions.show', $reception) }}"
                                                   class="btn btn-sm btn-outline-secondary" title="Просмотр">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                @if($reception->status != 'active')
                                                    <form action="{{ route('stone-receptions.reset-status', $reception) }}"
                                                          method="POST" class="d-inline"
                                                          onsubmit="return confirm('Сбросить статус на Активна?')">
                                                        @csrf
                                                        @method('PATCH')
                                                        <button type="submit" class="btn btn-sm btn-outline-warning" title="Сбросить статус">
                                                            <i class="bi bi-arrow-counterclockwise"></i>
                                                        </button>
                                                    </form>
                                                @endif
                                                @if($reception->status == 'active')
                                                    <form action="{{ route('stone-receptions.destroy', $reception) }}"
                                                          method="POST" class="d-inline"
                                                          onsubmit="return confirm('Удалить приёмку?')">
                                                        @csrf
                                                        @method('DELETE')
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

                    {{-- ─── МОБИЛЬНЫЙ: карточки ─── --}}
                    <div class="d-md-none" style="padding:.25rem">
                        @foreach($receptions as $reception)
                            @include('partials.reception-card', [
                                'reception'   => $reception,
                                'showActions' => true,
                            ])
                        @endforeach
                    </div>

                    {{-- Пагинация --}}
                    <div class="d-flex justify-content-between align-items-center p-2 p-md-3 border-top">
                        <span class="text-muted small">
                            Показано {{ $receptions->firstItem() }}–{{ $receptions->lastItem() }} из {{ $receptions->total() }}
                        </span>
                        {{ $receptions->links() }}
                    </div>

                </div>

            @else
                <div class="text-center py-5">
                    <i class="bi bi-inbox display-1 text-muted"></i>
                    <h4 class="text-muted mt-3">Приёмок не найдено</h4>
                </div>
            @endif
        </div>

        {{-- ═══════════════════════ ПО ПРИЁМКАМ ═══════════════════════ --}}
        <div id="view-logs">
            @if($logs->count() > 0)

                <div class="card shadow-sm">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
                        <span class="fw-semibold text-muted small"><i class="bi bi-list-ul me-1"></i> Записи приёмок</span>
                        <span class="text-muted small">Найдено: {{ $logs->total() }}</span>
                    </div>

                    {{-- ─── ДЕСКТОП: таблица ─── --}}
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

                    {{-- ─── МОБИЛЬНЫЙ: карточки ─── --}}
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

                </div>

            @else
                <div class="text-center py-5">
                    <i class="bi bi-journal-x display-1 text-muted"></i>
                    <h4 class="text-muted mt-3">Записей не найдено</h4>
                </div>
            @endif
        </div>

    </div>
@endsection

@push('scripts')
    @vite(['resources/js/product-picker.js'])
    <script>
    (function () {
        const VIEWS      = ['batches', 'logs'];
        const BTN_IDS    = { batches: 'view-btn-batches', logs: 'view-btn-logs' };
        const DIV_IDS    = { batches: 'view-batches',     logs: 'view-logs' };
        const STORAGE_KEY = 'stone_receptions_view';

        const statusCol = document.querySelector('[name="filter[status][]"]')?.closest('[class*="col-"]');
        const syncCol   = document.querySelector('[name="filter[sync_status][]"]')?.closest('[class*="col-"]');

        function applyView(key) {
            VIEWS.forEach(v => {
                const btn    = document.getElementById(BTN_IDS[v]);
                const div    = document.getElementById(DIV_IDS[v]);
                const active = v === key;
                if (div) div.style.display = active ? '' : 'none';
                if (btn) {
                    btn.style.background  = active ? '#0d6efd' : 'transparent';
                    btn.style.color       = active ? '#fff'    : '#0d6efd';
                    btn.style.fontWeight  = active ? '600'     : '500';
                    btn.style.boxShadow   = active ? '0 1px 4px rgba(13,110,253,.3)' : '';
                }
            });

            if (statusCol) statusCol.style.display = key === 'logs' ? 'none' : '';
            if (syncCol)   syncCol.style.display   = key === 'logs' ? 'none' : '';

            const viewInput = document.getElementById('filter-view-input');
            if (viewInput) viewInput.value = key;

            const params = new URLSearchParams(window.location.search);
            params.set('view', key);
            history.replaceState(null, '', '?' + params.toString());

            localStorage.setItem(STORAGE_KEY, key);
        }

        const urlView = new URLSearchParams(window.location.search).get('view');
        const saved   = VIEWS.includes(urlView) ? urlView
                      : (VIEWS.includes(localStorage.getItem(STORAGE_KEY)) ? localStorage.getItem(STORAGE_KEY) : 'batches');
        applyView(saved);

        VIEWS.forEach(v => {
            document.getElementById(BTN_IDS[v])?.addEventListener('click', () => applyView(v));
        });

        const filterForm = document.getElementById('filter-form');
        if (filterForm) {
            const hidden = document.createElement('input');
            hidden.type  = 'hidden';
            hidden.name  = 'view';
            hidden.id    = 'filter-view-input';
            hidden.value = localStorage.getItem(STORAGE_KEY) || 'batches';
            filterForm.appendChild(hidden);

            filterForm.addEventListener('submit', function () {
                hidden.value = localStorage.getItem(STORAGE_KEY) || 'batches';
            });
        }
    })();
    </script>
@endpush
