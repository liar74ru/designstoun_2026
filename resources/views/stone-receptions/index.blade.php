@extends('layouts.app')
@section('title', 'Приёмки камня')

@section('content')
    <div class="container py-3 py-md-4">

        <x-page-header title="📦 Приёмки камня" :hide-mobile="true">
            <x-slot:actions>
                @include('partials.help-button', ['page' => 'stone-receptions', 'class' => 'btn-outline-secondary btn-lg'])
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
            <div class="d-flex d-md-none gap-2">
                <a href="{{ route('stone-receptions.create') }}" class="btn btn-success flex-grow-1">
                    <i class="bi bi-plus-circle"></i> Новая приёмка
                </a>
                @include('partials.help-button', ['page' => 'stone-receptions'])
            </div>
        </div>

        {{-- ═══════════════════════ ФИЛЬТРЫ ═══════════════════════ --}}
        @include('partials.filters', [
            'filterCutters'      => $filterCutters,
            'cutterParam'        => 'cutter_id',
            'cutterDefaultIds'   => $cutterDefaultIds,
            'filterRawProducts'  => $filterRawProducts,
            'rawProductParam'    => 'raw_product_id',
            'filterProducts'     => $filterProducts,
            'showStatus'         => 'multi',
            'statusOptions'      => ['active' => 'Активна', 'completed' => 'Завершена', 'error' => 'Ошибка'],
            'statusDefaults'     => ['active', 'error'],
            'showSyncStatus'     => true,
            'syncStatusOptions'  => ['synced' => 'Синхронизирована', 'pending' => 'Ожидает', 'not_synced' => 'Не синхр.'],
            'filterDepartments'  => $filterDepartments,
            'departmentDefaults' => $departmentDefaults,
        ])

        {{-- ═══════════════════════ ПО ПАРТИЯМ ═══════════════════════ --}}
        <div id="view-batches">
            @if($receptions->count() > 0 || $batchesWithoutReceptions->count() > 0)

                <div class="card shadow-sm">
                    <div class="card-header bg-white d-flex justify-content-end align-items-center py-2">
                        <span class="text-muted small">Найдено: {{ $receptions->total() }}@if($batchesWithoutReceptions->count() > 0) <span class="text-info">(+{{ $batchesWithoutReceptions->count() }} без приёмок)</span>@endif</span>
                    </div>

                    {{-- ─── ДЕСКТОП: шкала времени ─── --}}
                    <div class="d-none d-md-block p-3">
                        @if($batchesWithoutReceptions->count() > 0)
                            <div class="fw-semibold text-info mb-2" style="font-size:.8rem">
                                <i class="bi bi-hourglass-split me-1"></i>Партии без приёмок
                            </div>
                            @foreach($batchesWithoutReceptions as $batch)
                                <x-timeline-item :time="$batch->created_at" color="var(--bs-info)">
                                    @include('stone-receptions._batch-card', ['batch' => $batch])
                                </x-timeline-item>
                            @endforeach
                        @endif
                        @php $prevDay = null; @endphp
                        @foreach($receptions as $reception)
                            @php
                                $day      = $reception->created_at->toDateString();
                                $dotColor = match ($reception->status) {
                                    'active'    => 'var(--bs-success)',
                                    'completed' => 'var(--bs-warning)',
                                    'processed' => 'var(--bs-secondary)',
                                    default     => 'var(--bs-danger)',
                                };
                            @endphp
                            @if($day !== $prevDay)
                                <x-timeline-day :date="$reception->created_at" :first="$loop->first && $batchesWithoutReceptions->isEmpty()" />
                                @php $prevDay = $day; @endphp
                            @endif
                            <x-timeline-item :time="$reception->created_at" :color="$dotColor">
                                @include('partials.reception-card', ['reception' => $reception, 'showActions' => true])
                            </x-timeline-item>
                        @endforeach
                    </div>

                    {{-- ─── МОБИЛЬНЫЙ: карточки ─── --}}
                    <div class="d-md-none" style="padding:.25rem">
                        @foreach($batchesWithoutReceptions as $batch)
                            @include('stone-receptions._batch-card', ['batch' => $batch])
                        @endforeach
                        @foreach($receptions as $reception)
                            @include('partials.reception-card', [
                                'reception'   => $reception,
                                'showActions' => true,
                            ])
                        @endforeach
                    </div>

                    {{-- Пагинация --}}
                    @if($receptions->total() > 0)
                        <div class="d-flex justify-content-between align-items-center p-2 p-md-3 border-top">
                            <span class="text-muted small">
                                Показано {{ $receptions->firstItem() }}–{{ $receptions->lastItem() }} из {{ $receptions->total() }}
                            </span>
                            {{ $receptions->links() }}
                        </div>
                    @endif

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

                    {{-- ─── ДЕСКТОП: шкала времени ─── --}}
                    <div class="d-none d-md-block p-3">
                        @php $prevDay = null; @endphp
                        @foreach($logs as $log)
                            @php $day = $log->created_at->toDateString(); @endphp
                            @if($day !== $prevDay)
                                <x-timeline-day :date="$log->created_at" :first="$loop->first" />
                                @php $prevDay = $day; @endphp
                            @endif
                            <x-timeline-item :time="$log->created_at" :color="$log->type === 'created' ? 'var(--bs-success)' : 'var(--bs-warning)'">
                                @include('partials.reception-log-card', [
                                    'log'             => $log,
                                    'showActions'     => true,
                                    'showRawDetails'  => true,
                                    'showStoreBottom' => false,
                                    'showSyncStatus'  => true,
                                ])
                            </x-timeline-item>
                        @endforeach
                    </div>

                    {{-- ─── МОБИЛЬНЫЙ: карточки ─── --}}
                    <div class="d-md-none" style="padding:.25rem">
                        @foreach($logs as $log)
                            @include('partials.reception-log-card', [
                                'log'             => $log,
                                'showActions'     => true,
                                'showRawDetails'  => true,
                                'showStoreBottom' => false,
                                'showSyncStatus'  => true,
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
    <script>
    (function () {
        const VIEWS      = ['batches', 'logs'];
        const BTN_IDS    = { batches: 'view-btn-batches', logs: 'view-btn-logs' };
        const DIV_IDS    = { batches: 'view-batches',     logs: 'view-logs' };
        const STORAGE_KEY = 'stone_receptions_view';

        const statusCol = document.querySelector('[name="filter[status][]"]')?.closest('[class*="col-"]');

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
