@extends('layouts.app')
@section('title', 'Приёмки камня — журнал логов')

@section('content')
    <div class="container py-4">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2 mb-0">📋 Журнал логов приёмок</h1>
            <a href="{{ route('stone-receptions.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Новая приёмка
            </a>
        </div>

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill"></i> {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        <ul class="nav nav-pills mb-4">
            <li class="nav-item">
                <a class="nav-link" href="{{ route('stone-receptions.index') }}">
                    <i class="bi bi-table"></i> По партиям
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="{{ route('stone-receptions.logs') }}">
                    <i class="bi bi-journal-text"></i> Журнал логов
                </a>
            </li>
        </ul>

        {{-- ФОРМА ФИЛЬТРОВ (модалка вынесена после закрытия form) --}}
        <form method="GET" id="filter-form" class="card shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-2"
                 style="cursor:pointer" id="filter-toggle" role="button">
            <span class="fw-semibold text-muted small">
                <i class="bi bi-funnel me-1"></i> Фильтры
                <span id="filter-active-badge" class="ms-1"></span>
            </span>
                <i class="bi bi-chevron-up" id="filter-chevron"></i>
            </div>
            <div id="filter-collapse">
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                        <span class="text-muted small fw-semibold me-1">Период:</span>
                        @foreach([0 => 'Текущая неделя', 1 => 'Прошлая неделя', 2 => '2 недели назад'] as $w => $lbl)
                            @php
                                $fri = \Carbon\Carbon::today();
                                while ($fri->dayOfWeek !== \Carbon\Carbon::FRIDAY) $fri->subDay();
                                $fri->subDays($w * 7);
                                $thu = $fri->copy()->addDays(6);
                                $weekActive = request('date_from') === $fri->format('Y-m-d')
                                           && request('date_to')   === $thu->format('Y-m-d');
                            @endphp
                            <button type="button"
                                    class="btn btn-sm {{ $weekActive ? 'btn-secondary' : 'btn-outline-secondary' }} week-btn"
                                    data-from="{{ $fri->format('Y-m-d') }}"
                                    data-to="{{ $thu->format('Y-m-d') }}">{{ $lbl }}</button>
                        @endforeach
                        <button type="button"
                                class="btn btn-sm {{ !request('date_from') && !request('date_to') ? 'btn-secondary' : 'btn-outline-secondary' }}"
                                id="btn-all-time">За всё время</button>
                        <div class="d-flex gap-2 align-items-center ms-1">
                            <label class="small text-muted mb-0">С</label>
                            <input type="date" name="date_from" id="date_from"
                                   class="form-control form-control-sm" style="width:145px"
                                   value="{{ request('date_from') }}">
                            <label class="small text-muted mb-0">По</label>
                            <input type="date" name="date_to" id="date_to"
                                   class="form-control form-control-sm" style="width:145px"
                                   value="{{ request('date_to') }}">
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-sm-6 col-lg-4">
                            <label class="form-label small text-muted mb-1">Пильщик</label>
                            <select name="filter[cutter_id]" class="form-select form-select-sm">
                                <option value="">Все пильщики</option>
                                @foreach($filterCutters as $cutter)
                                    <option value="{{ $cutter->id }}"
                                        {{ request('filter.cutter_id') == $cutter->id ? 'selected' : '' }}>
                                        {{ $cutter->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <label class="form-label small text-muted mb-1">Сырьё (партия)</label>
                            <select name="filter[raw_material_batch_id]" class="form-select form-select-sm">
                                <option value="">Все партии</option>
                                @foreach($filterBatches as $batch)
                                    <option value="{{ $batch->id }}"
                                        {{ request('filter.raw_material_batch_id') == $batch->id ? 'selected' : '' }}>
                                        #{{ $batch->id }} — {{ $batch->product->name ?? '?' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <label class="form-label small text-muted mb-1">Продукт</label>
                            @php
                                $pickerValue = request('filter.product_id', '');
                                $pickerLabel = $filterProducts->firstWhere('id', $pickerValue)?->name ?? '';
                            @endphp
                            <div class="product-picker-row" data-index="filter">
                                <div class="flex-grow-1 position-relative">
                                    <div class="input-group input-group-sm">
                                        <input type="text"
                                               id="product_filter_search"
                                               class="form-control product-picker-search"
                                               placeholder="Введите название..."
                                               autocomplete="off"
                                               value="{{ $pickerLabel }}">
                                        <button type="button"
                                                class="btn btn-outline-secondary btn-sm product-picker-tree-btn"
                                                data-modal="modal_filter_product"
                                                data-hidden-id="filter_product_id"
                                                data-search-id="product_filter_search"
                                                title="Выбрать из каталога">
                                            <i class="bi bi-diagram-3"></i>
                                        </button>
                                        @if($pickerValue)
                                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                                    id="clear_product_filter" title="Сбросить">
                                                <i class="bi bi-x"></i>
                                            </button>
                                        @endif
                                    </div>
                                    <div class="product-picker-dropdown list-group shadow-sm"
                                         style="display:none;position:absolute;z-index:1050;width:100%;max-height:280px;overflow-y:auto">
                                    </div>
                                </div>
                                <input type="hidden" id="filter_product_id"
                                       name="filter[product_id]" value="{{ $pickerValue }}">
                            </div>
                        </div>
                    </div>
                </div>
            </div>{{-- /filter-collapse --}}
            <div class="card-footer bg-white d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-funnel"></i> Применить
                </button>
                <a href="{{ url()->current() }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-x-circle"></i> Сбросить
                </a>
            </div>
        </form>

        {{-- Модальное дерево — строго вне <form> --}}
        <div class="modal fade" id="modal_filter_product" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Выбрать продукт</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" style="max-height:70vh;overflow-y:auto">
                        <input type="text" class="form-control mb-3 tree-search-input"
                               placeholder="Поиск по каталогу...">
                        <div class="product-tree-container"></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ТАБЛИЦА --}}
        @if($logs->count() > 0)
            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-end">
                    <span class="text-muted small">Найдено: {{ $logs->total() }}</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>Дата</th>
                            <th>Пильщик</th>
                            <th>Приёмщик</th>
                            <th>Склад</th>
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
                                <td>{{ $log->cutter->name ?? '—' }}</td>
                                <td>{{ $log->receiver->name ?? '—' }}</td>
                                <td>{{ $log->stoneReception?->store?->name ?? '—' }}</td>
                                <td>
                                    @if($log->rawMaterialBatch)
                                        <a href="{{ route('raw-batches.show', $log->rawMaterialBatch) }}">
                                            {{ $log->rawMaterialBatch->product->name ?? '?' }}
                                        </a>
                                        <br><small class="text-muted">#{{ $log->raw_material_batch_id }}</small>
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
                                               class="btn btn-sm btn-outline-primary" title="Редактировать">
                                                <i class="bi bi-pencil"></i>
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
            <div class="d-flex justify-content-between align-items-center mt-3">
            <span class="text-muted small">
                Показано {{ $logs->firstItem() }}–{{ $logs->lastItem() }} из {{ $logs->total() }}
            </span>
                {{ $logs->links() }}
            </div>
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
    <script>
        document.addEventListener('DOMContentLoaded', function () {

            (function () {
                const STORAGE_KEY = 'filter_collapsed_' + window.location.pathname.replace(/\//g, '_');
                const collapse = document.getElementById('filter-collapse');
                const chevron  = document.getElementById('filter-chevron');
                const toggle   = document.getElementById('filter-toggle');
                const badge    = document.getElementById('filter-active-badge');

                const params = new URLSearchParams(window.location.search);
                const activeFilters = ['filter[cutter_id]','filter[raw_material_batch_id]','filter[product_id]','date_from','date_to']
                    .filter(k => params.get(k) && params.get(k) !== '').length;

                if (badge && activeFilters > 0) {
                    badge.innerHTML = `<span class="badge bg-primary rounded-pill">${activeFilters}</span>`;
                }

                const shouldCollapse = activeFilters === 0 && localStorage.getItem(STORAGE_KEY) === '1';

                function applyState(collapsed, animate) {
                    if (collapsed) {
                        if (animate) {
                            collapse.style.opacity = '0';
                            setTimeout(() => { collapse.style.display = 'none'; collapse.style.opacity = ''; }, 150);
                        } else {
                            collapse.style.display = 'none';
                        }
                        chevron.className = 'bi bi-chevron-down';
                    } else {
                        collapse.style.display = '';
                        collapse.style.opacity = '0';
                        if (animate) setTimeout(() => { collapse.style.opacity = '1'; }, 10);
                        else collapse.style.opacity = '';
                        chevron.className = 'bi bi-chevron-up';
                    }
                }

                applyState(shouldCollapse, false);
                toggle.addEventListener('click', function () {
                    const isHidden = collapse.style.display === 'none';
                    applyState(!isHidden, true);
                    localStorage.setItem(STORAGE_KEY, isHidden ? '0' : '1');
                });
            })();

            const form     = document.getElementById('filter-form');
            const dateFrom = document.getElementById('date_from');
            const dateTo   = document.getElementById('date_to');

            document.querySelectorAll('.week-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    dateFrom.value = btn.dataset.from;
                    dateTo.value   = btn.dataset.to;
                    form.submit();
                });
            });
            document.getElementById('btn-all-time')?.addEventListener('click', () => {
                dateFrom.value = ''; dateTo.value = ''; form.submit();
            });
            document.getElementById('clear_product_filter')?.addEventListener('click', () => {
                document.getElementById('filter_product_id').value = '';
                document.getElementById('product_filter_search').value = '';
                form.submit();
            });
            document.addEventListener('product-picker:selected', () => {
                if (document.getElementById('filter_product_id')?.value) form.submit();
            });
        });
    </script>
@endpush
