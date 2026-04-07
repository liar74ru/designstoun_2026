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
        <form method="GET" id="filter-form" class="card shadow-sm mb-2 mb-md-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-2"
                 style="cursor:pointer" id="filter-toggle" role="button">
                <span class="fw-semibold text-muted small">
                    <i class="bi bi-funnel me-1"></i> Фильтры
                    <span id="filter-active-badge" class="ms-1"></span>
                </span>
                <i class="bi bi-chevron-down" id="filter-chevron"></i>
            </div>

            <div id="filter-collapse" style="display:none">
                <div class="card-body pb-2">

                    {{-- Период: быстрые кнопки --}}
                    <div class="d-flex flex-wrap gap-1 gap-md-2 align-items-center mb-2">
                        <span class="text-muted small fw-semibold me-1 d-none d-sm-inline">Период:</span>
                        @foreach([0 => 'Тек. нед.', 1 => 'Пред. нед.', 2 => '2 нед. назад'] as $w => $lbl)
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
                                id="btn-all-time">Всё время</button>
                    </div>

                    {{-- Период: ручной ввод дат --}}
                    <div class="row g-2 mb-3">
                        <div class="col-6 col-sm-auto">
                            <label class="form-label small text-muted mb-1">С</label>
                            <input type="date" name="date_from" id="date_from"
                                   class="form-control form-control-sm"
                                   value="{{ request('date_from') }}">
                        </div>
                        <div class="col-6 col-sm-auto">
                            <label class="form-label small text-muted mb-1">По</label>
                            <input type="date" name="date_to" id="date_to"
                                   class="form-control form-control-sm"
                                   value="{{ request('date_to') }}">
                        </div>
                    </div>

                    {{-- Фильтры: пильщик, тип сырья, продукт --}}
                    <div class="row g-2">
                        <div class="col-12 col-sm-6 col-lg-4">
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

                        {{-- Тип сырья (продукт партии) — через product-picker --}}
                        <div class="col-12 col-sm-6 col-lg-4">
                            <label class="form-label small text-muted mb-1">Тип сырья</label>
                            @php
                                $rawPickerValue = request('filter.raw_material_product_id', '');
                                $rawPickerLabel = $filterRawProducts->firstWhere('id', $rawPickerValue)?->name ?? '';
                            @endphp
                            <div class="product-picker-row" data-index="filter-raw">
                                <div class="flex-grow-1 position-relative">
                                    <div class="input-group input-group-sm">
                                        <input type="text"
                                               id="raw_product_filter_search"
                                               class="form-control product-picker-search"
                                               placeholder="Введите название..."
                                               autocomplete="off"
                                               value="{{ $rawPickerLabel }}">
                                        <button type="button"
                                                class="btn btn-outline-secondary btn-sm product-picker-tree-btn"
                                                data-modal="modal_filter_raw_product"
                                                data-hidden-id="filter_raw_product_id"
                                                data-search-id="raw_product_filter_search"
                                                title="Выбрать из каталога">
                                            <i class="bi bi-diagram-3"></i>
                                        </button>
                                        @if($rawPickerValue)
                                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                                    id="clear_raw_product_filter" title="Сбросить">
                                                <i class="bi bi-x"></i>
                                            </button>
                                        @endif
                                    </div>
                                    <div class="product-picker-dropdown list-group shadow-sm"
                                         style="display:none;position:absolute;z-index:1050;width:100%;max-height:280px;overflow-y:auto">
                                    </div>
                                </div>
                                <input type="hidden" id="filter_raw_product_id"
                                       name="filter[raw_material_product_id]" value="{{ $rawPickerValue }}">
                            </div>
                        </div>

                        {{-- Продукт (плитка) --}}
                        <div class="col-12 col-sm-12 col-lg-4">
                            <label class="form-label small text-muted mb-1">Продукт (плитка)</label>
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

                    <div class="d-flex gap-2 mt-2">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-funnel"></i> Применить
                        </button>
                        <a href="{{ url()->current() }}" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-x-circle"></i> Сбросить
                        </a>
                    </div>

                </div>
            </div>{{-- /filter-collapse --}}
        </form>

        {{-- Модальные окна каталога — строго вне <form> --}}
        <div class="modal fade" id="modal_filter_raw_product" tabindex="-1">
            <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Выбрать тип сырья</h5>
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

        <div class="modal fade" id="modal_filter_product" tabindex="-1">
            <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Выбрать продукт (плитка)</h5>
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
                    @php
                        $skuColor = \App\Models\Product::getColorBySku($log->rawMaterialBatch?->product?->sku);
                        $skuBg    = $skuColor === '#FFFFFF' ? '#fff' : $skuColor . '18';
                    @endphp
                    <div style="margin-bottom:.35rem;border-radius:.35rem;border:1px solid #dee2e6;border-left:4px solid {{ $skuColor }};border-right:4px solid {{ $skuColor }};background:{{ $skuBg }};box-shadow:0 1px 2px rgba(0,0,0,.07)">
                        <div style="padding:.1rem .35rem">

                            {{-- Строка 1: Дата слева, кнопки справа --}}
                            <div class="d-flex justify-content-between align-items-center" style="margin-bottom:.2rem">
                                <span class="text-muted" style="font-size:.72rem">{{ $log->created_at->format('d.m.Y H:i') }}</span>
                                <div class="d-flex gap-1 align-items-center">
                                    @if($log->stoneReception && $log->stoneReception->status === 'active')
                                        <a href="{{ route('stone-receptions.edit', $log->stone_reception_id) }}"
                                           class="btn btn-success d-inline-flex align-items-center justify-content-center"
                                           style="width:22px;height:22px;padding:0;font-size:.65rem"
                                           title="Редактировать">
                                            <i class="bi bi-plus-lg"></i>
                                        </a>
                                    @endif
                                    @if($log->stoneReception)
                                        <form action="{{ route('stone-receptions.copy', $log->stone_reception_id) }}"
                                              method="POST" class="d-inline-flex">
                                            @csrf
                                            <button type="submit"
                                                    class="btn btn-outline-info d-inline-flex align-items-center justify-content-center"
                                                    style="width:22px;height:22px;padding:0;font-size:.65rem"
                                                    title="Копировать">
                                                <i class="bi bi-copy"></i>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>

                            {{-- Строка 2: Пильщик слева, статус справа --}}
                            <div class="d-flex justify-content-between align-items-center" style="margin-bottom:.2rem">
                                <span class="fw-semibold small">
                                    <i class="bi bi-hammer text-secondary me-1"></i>{{ $log->cutter->name ?? '—' }}
                                </span>
                                @if($log->type === 'created')
                                    <span class="badge bg-success" style="font-size:.65rem">Создание</span>
                                @else
                                    <span class="badge bg-warning text-dark" style="font-size:.65rem">Правка</span>
                                @endif
                            </div>

                            {{-- Блок: плитка --}}
                            @if($log->items->count() > 0)
                                <div style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem;margin-bottom:.2rem">
                                    @foreach($log->items as $item)
                                        @php $delta = (float) $item->quantity_delta; @endphp
                                        <div class="d-flex justify-content-between align-items-baseline" style="{{ !$loop->last ? 'margin-bottom:.1rem' : '' }}">
                                            <span class="text-truncate me-2" style="font-size:.72rem;max-width:80%">
                                                <i class="bi bi-grid-3x3 text-secondary me-1"></i>{{ $item->product?->name ?? '?' }}
                                            </span>
                                            <span class="fw-semibold {{ $delta >= 0 ? 'text-success' : 'text-danger' }} text-nowrap" style="font-size:.72rem">
                                                {{ $delta >= 0 ? '+' : '' }}{{ number_format($delta, 3, ',', '.') }} м²
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            {{-- Блок: партия сырья --}}
                            @if($log->rawMaterialBatch)
                                @php
                                    $rawDelta       = (float) $log->raw_quantity_delta;
                                    $snapshot       = $log->raw_quantity_snapshot !== null ? (float) $log->raw_quantity_snapshot : null;
                                    $batchRemaining = (float) ($log->rawMaterialBatch->remaining_quantity ?? 0);
                                    $deltaDisplay   = $rawDelta != 0 ? ($rawDelta > 0 ? '-' : '+') . number_format(abs($rawDelta), 3, '.', '') : '0.000';
                                    $deltaIsConsume = $rawDelta > 0;
                                @endphp
                                <div class="d-flex justify-content-between align-items-center" style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem;margin-bottom:.2rem">
                                    <span class="text-muted text-truncate me-2" style="font-size:.72rem">
                                        <i class="bi bi-box me-1"></i>
                                        <a href="{{ route('raw-batches.show', $log->rawMaterialBatch) }}" class="text-muted">
                                            {{ $log->rawMaterialBatch->product->name ?? '?' }}
                                        </a>
                                    </span>
                                    <div class="d-flex gap-1 flex-shrink-0">
                                        <span title="Было в партии на момент приёмки" style="font-size:.65rem;padding:1px 4px;border-radius:3px;background:#e9ecef;color:#495057;white-space:nowrap">{{ $snapshot !== null ? number_format($snapshot, 3, '.', '') : '—' }}</span>
                                        <span title="Изменение сырья в этой приёмке" style="font-size:.65rem;padding:1px 4px;border-radius:3px;background:{{ $deltaIsConsume ? '#f8d7da' : '#d1e7dd' }};color:{{ $deltaIsConsume ? '#842029' : '#0a3622' }};white-space:nowrap">{{ $deltaDisplay }}</span>
                                        <span title="Текущий остаток в партии" style="font-size:.65rem;padding:1px 4px;border-radius:3px;background:{{ $batchRemaining > 0 ? '#cff4fc' : '#fff3cd' }};color:{{ $batchRemaining > 0 ? '#055160' : '#664d03' }};white-space:nowrap">{{ number_format($batchRemaining, 3, '.', '') }}</span>
                                    </div>
                                </div>
                            @endif

                            {{-- Последняя строка: приёмщик справа --}}
                            @if($log->receiver)
                                <div class="d-flex justify-content-end" style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem">
                                    <span class="text-muted" style="font-size:.65rem">
                                        <i class="bi bi-person-gear me-1"></i>{{ $log->receiver->name }}
                                    </span>
                                </div>
                            @endif

                        </div>
                    </div>
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
    <script>
        document.addEventListener('DOMContentLoaded', function () {

            (function () {
                const STORAGE_KEY = 'filter_collapsed_' + window.location.pathname.replace(/\//g, '_');
                const collapse = document.getElementById('filter-collapse');
                const chevron  = document.getElementById('filter-chevron');
                const toggle   = document.getElementById('filter-toggle');
                const badge    = document.getElementById('filter-active-badge');

                const params = new URLSearchParams(window.location.search);
                const activeFilters = [
                    'filter[cutter_id]', 'filter[raw_material_product_id]',
                    'filter[product_id]', 'date_from', 'date_to'
                ].filter(k => params.get(k) && params.get(k) !== '').length;

                if (badge && activeFilters > 0) {
                    badge.innerHTML = `<span class="badge bg-primary rounded-pill">${activeFilters}</span>`;
                }

                // Фильтр скрыт по умолчанию; раскрывается если есть активные фильтры
                // или пользователь явно открыл его ранее
                const userOpened   = localStorage.getItem(STORAGE_KEY) === 'open';
                const shouldExpand = activeFilters > 0 || userOpened;

                function applyState(expanded, animate) {
                    if (!expanded) {
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

                applyState(shouldExpand, false);
                toggle.addEventListener('click', function () {
                    const isHidden = collapse.style.display === 'none';
                    applyState(isHidden, true);
                    localStorage.setItem(STORAGE_KEY, isHidden ? 'open' : 'closed');
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

            // Сброс фильтра по продукту (плитка)
            document.getElementById('clear_product_filter')?.addEventListener('click', () => {
                document.getElementById('filter_product_id').value = '';
                document.getElementById('product_filter_search').value = '';
                form.submit();
            });
            // Сброс фильтра по типу сырья
            document.getElementById('clear_raw_product_filter')?.addEventListener('click', () => {
                document.getElementById('filter_raw_product_id').value = '';
                document.getElementById('raw_product_filter_search').value = '';
                form.submit();
            });

            document.addEventListener('product-picker:selected', (e) => {
                // Авто-применяем фильтр при выборе любого продукта
                const hiddenId = e.detail?.hiddenInputId;
                if (hiddenId === 'filter_product_id' || hiddenId === 'filter_raw_product_id') {
                    form.submit();
                }
            });
        });
    </script>
@endpush
