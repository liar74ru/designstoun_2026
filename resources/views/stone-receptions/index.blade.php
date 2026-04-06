@extends('layouts.app')
@section('title', 'Приёмки камня — по партиям')

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

        @include('stone-receptions.partials.mobile-tabs', ['activeTab' => 'index'])

        {{-- Десктоп: переключатель вида --}}
        <ul class="nav nav-pills mb-3 mb-md-4 d-none d-md-flex">
            <li class="nav-item">
                <a class="nav-link active py-1 px-3" href="{{ route('stone-receptions.index') }}">
                    <i class="bi bi-table"></i> По партиям
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link py-1 px-3" href="{{ route('stone-receptions.logs') }}">
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

                    {{-- Период: ручной ввод --}}
                    <div class="row g-2 mb-3">
                        <div class="col-6 col-sm-auto">
                            <label class="form-label small text-muted mb-1">С</label>
                            <input type="date" name="date_from" id="date_from"
                                   class="form-control form-control-sm" value="{{ request('date_from') }}">
                        </div>
                        <div class="col-6 col-sm-auto">
                            <label class="form-label small text-muted mb-1">По</label>
                            <input type="date" name="date_to" id="date_to"
                                   class="form-control form-control-sm" value="{{ request('date_to') }}">
                        </div>
                    </div>

                    {{-- Фильтры --}}
                    <div class="row g-2">
                        <div class="col-12 col-sm-6 col-lg-3">
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
                        <div class="col-12 col-sm-6 col-lg-3">
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
                        <div class="col-12 col-sm-6 col-lg-3">
                            <label class="form-label small text-muted mb-1">Продукт</label>
                            @php
                                $pickerValue = request('filter.product_id', '');
                                $pickerLabel = $filterProducts->firstWhere('id', $pickerValue)?->name ?? '';
                            @endphp
                            <div class="product-picker-row" data-index="filter">
                                <div class="flex-grow-1 position-relative">
                                    <div class="input-group input-group-sm">
                                        <input type="text" id="product_filter_search"
                                               class="form-control product-picker-search"
                                               placeholder="Введите название..."
                                               autocomplete="off" value="{{ $pickerLabel }}">
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
                        <div class="col-12 col-sm-6 col-lg-3">
                            <label class="form-label small text-muted mb-1">Статус</label>
                            <div class="d-flex flex-wrap gap-2 mt-1">
                                @foreach(['active' => 'Активна', 'completed' => 'Завершена', 'processed' => 'Обработана', 'error' => 'Ошибка'] as $val => $lbl)
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="checkbox"
                                               name="filter[status][]" value="{{ $val }}"
                                               id="status_{{ $val }}"
                                            {{ in_array($val, (array) request('filter.status', [])) ? 'checked' : '' }}>
                                        <label class="form-check-label small" for="status_{{ $val }}">{{ $lbl }}</label>
                                    </div>
                                @endforeach
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
            </div>
        </form>

        {{-- Модальное дерево продуктов --}}
        <div class="modal fade" id="modal_filter_product" tabindex="-1">
            <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
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

        {{-- ═══════════════════════ ДАННЫЕ ═══════════════════════ --}}
        @if($receptions->count() > 0)

            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
                    <button type="button" class="btn btn-success btn-sm" id="sendToMoySkladBtn" disabled>
                        <i class="bi bi-cloud-upload"></i>
                        <span class="d-none d-sm-inline">Отправить в МойСклад</span>
                        (<span id="selectedCount">0</span>)
                    </button>
                    <span class="text-muted small">Найдено: {{ $receptions->total() }}</span>
                </div>

                {{-- ─── ДЕСКТОП: таблица ─── --}}
                <div class="d-none d-md-block">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                            <tr>
                                <th width="40"><input type="checkbox" class="form-check-input" id="selectAll"></th>
                                <th>#</th>
                                <th>Дата</th>
                                <th>Продукция</th>
                                <th>Итого</th>
                                <th>Сырьё</th>
                                <th>Расход</th>
                                <th>Приёмщик</th>
                                <th>Пильщик</th>
                                <th>Склад</th>
                                <th>Статус</th>
                                <th class="text-end">Действия</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($receptions as $reception)
                                <tr class="{{ $reception->status == 'processed' ? 'table-success' : ($reception->status == 'completed' ? 'table-warning' : ($reception->status == 'error' ? 'table-danger' : '')) }}">
                                    <td>
                                        @if(!in_array($reception->status, ['processed', 'error']))
                                            <input type="checkbox" class="form-check-input reception-checkbox"
                                                   value="{{ $reception->id }}">
                                        @endif
                                    </td>
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
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1 justify-content-end">
                                            @if($reception->status == 'active')
                                                <a href="{{ route('stone-receptions.edit', $reception) }}"
                                                   class="btn btn-sm btn-success" title="Редактировать">
                                                    <i class="bi bi-plus-lg"></i>
                                                </a>
                                            @endif
                                            @if($reception->status == 'active' && $reception->rawMaterialBatch && (float)$reception->rawMaterialBatch->remaining_quantity <= 0)
                                                <form method="POST" action="{{ route('stone-receptions.mark-completed', $reception) }}" class="d-inline"
                                                      onsubmit="return confirm('Завершить приёмку? Сырьё израсходовано — партия будет закрыта.')">
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
                        @php
                            $skuColor = \App\Models\Product::getColorBySku($reception->rawMaterialBatch?->product?->sku);
                            $skuBg    = $skuColor === '#FFFFFF' ? '#fff' : $skuColor . '18';
                        @endphp
                        <div style="margin-bottom:.35rem;border-radius:.35rem;border:1px solid #dee2e6;border-left:4px solid {{ $skuColor }};border-right:4px solid {{ $skuColor }};background:{{ $skuBg }};box-shadow:0 1px 2px rgba(0,0,0,.07)">
                            <div style="padding:.1rem .35rem">

                                {{-- Строка 1: дата + ID слева, чекбокс + кнопки справа --}}
                                <div class="d-flex justify-content-between align-items-center" style="margin-bottom:.2rem">
                                    <span class="text-muted" style="font-size:.72rem">
                                        {{ $reception->created_at->format('d.m.Y H:i') }}
                                        <span class="text-secondary ms-1">#{{ $reception->id }}</span>
                                    </span>
                                    <div class="d-flex gap-1 align-items-center">
                                        @if(!in_array($reception->status, ['processed', 'error']))
                                            <input type="checkbox" class="form-check-input reception-checkbox"
                                                   value="{{ $reception->id }}"
                                                   style="width:14px;height:14px;margin:0">
                                        @endif
                                        @if($reception->status == 'active')
                                            <a href="{{ route('stone-receptions.edit', $reception) }}"
                                               class="btn btn-success d-inline-flex align-items-center justify-content-center"
                                               style="width:22px;height:22px;padding:0;font-size:.65rem" title="Редактировать">
                                                <i class="bi bi-plus-lg"></i>
                                            </a>
                                        @endif
                                        @if($reception->status == 'active' && $reception->rawMaterialBatch && (float)$reception->rawMaterialBatch->remaining_quantity <= 0)
                                            <form action="{{ route('stone-receptions.mark-completed', $reception) }}"
                                                  method="POST" class="d-inline-flex"
                                                  onsubmit="return confirm('Завершить приёмку? Сырьё израсходовано — партия будет закрыта.')">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit"
                                                        class="btn btn-warning d-inline-flex align-items-center justify-content-center"
                                                        style="width:22px;height:22px;padding:0;font-size:.65rem" title="Завершить приёмку">
                                                    <i class="bi bi-check2-circle"></i>
                                                </button>
                                            </form>
                                        @endif
                                        <form action="{{ route('stone-receptions.copy', $reception) }}"
                                              method="POST" class="d-inline-flex">
                                            @csrf
                                            <button type="submit"
                                                    class="btn btn-outline-info d-inline-flex align-items-center justify-content-center"
                                                    style="width:22px;height:22px;padding:0;font-size:.65rem" title="Копировать">
                                                <i class="bi bi-copy"></i>
                                            </button>
                                        </form>
                                        <a href="{{ route('stone-receptions.show', $reception) }}"
                                           class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center"
                                           style="width:22px;height:22px;padding:0;font-size:.65rem" title="Просмотр">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        @if($reception->status != 'active')
                                            <form action="{{ route('stone-receptions.reset-status', $reception) }}"
                                                  method="POST" class="d-inline-flex"
                                                  onsubmit="return confirm('Сбросить статус на Активна?')">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit"
                                                        class="btn btn-outline-warning d-inline-flex align-items-center justify-content-center"
                                                        style="width:22px;height:22px;padding:0;font-size:.65rem" title="Сбросить статус">
                                                    <i class="bi bi-arrow-counterclockwise"></i>
                                                </button>
                                            </form>
                                        @endif
                                        @if($reception->status == 'active')
                                            <form action="{{ route('stone-receptions.destroy', $reception) }}"
                                                  method="POST" class="d-inline-flex"
                                                  onsubmit="return confirm('Удалить приёмку?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="btn btn-outline-danger d-inline-flex align-items-center justify-content-center"
                                                        style="width:22px;height:22px;padding:0;font-size:.65rem" title="Удалить">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </div>

                                {{-- Строка 2: пильщик слева, статус справа --}}
                                <div class="d-flex justify-content-between align-items-center" style="margin-bottom:.2rem">
                                    <span class="fw-semibold small">
                                        <i class="bi bi-hammer text-secondary me-1"></i>{{ $reception->cutter->name ?? '—' }}
                                    </span>
                                    @if($reception->status == 'active')
                                        <span class="badge bg-success" style="font-size:.65rem">Активна</span>
                                    @elseif($reception->status == 'completed')
                                        <span class="badge bg-warning text-dark" style="font-size:.65rem">Завершена</span>
                                    @elseif($reception->status == 'processed')
                                        <span class="badge bg-secondary" style="font-size:.65rem">Обработана</span>
                                    @elseif($reception->status == 'error')
                                        <span class="badge bg-danger" style="font-size:.65rem">Ошибка</span>
                                    @endif
                                </div>

                                {{-- Блок: продукция --}}
                                @if($reception->items->count() > 0)
                                    <div style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem;margin-bottom:.2rem">
                                        @foreach($reception->items as $item)
                                            <div class="d-flex justify-content-between align-items-baseline" style="{{ !$loop->last ? 'margin-bottom:.1rem' : '' }}">
                                                <span class="text-truncate me-2" style="font-size:.72rem;max-width:65%">
                                                    <i class="bi bi-grid-3x3 text-secondary me-1"></i>{{ $item->product->name }}
                                                </span>
                                                <span class="fw-semibold text-primary text-nowrap" style="font-size:.72rem">
                                                    {{ number_format($item->quantity, 3, ',', '.') }} м²
                                                </span>
                                            </div>
                                        @endforeach
                                        <div class="d-flex justify-content-end" style="margin-top:.15rem">
                                            <span class="fw-semibold text-nowrap" style="font-size:.72rem">
                                                Итого: {{ number_format($reception->total_quantity, 3, ',', '.') }} м²
                                            </span>
                                        </div>
                                    </div>
                                @endif

                                {{-- Блок: сырьё --}}
                                @if($reception->rawMaterialBatch)
                                    @php
                                        $bInit = (float) ($reception->rawMaterialBatch->initial_quantity ?? 0);
                                        $bRem  = (float) ($reception->rawMaterialBatch->remaining_quantity ?? 0);
                                    @endphp
                                    <div class="d-flex justify-content-between align-items-center" style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem;margin-bottom:.2rem">
                                        <span class="text-muted text-truncate me-2" style="font-size:.72rem">
                                            <i class="bi bi-box me-1"></i>
                                            <a href="{{ route('raw-batches.show', $reception->rawMaterialBatch) }}" class="text-muted">
                                                {{ $reception->rawMaterialBatch->product->name }}
                                            </a>
                                        </span>
                                        <div class="d-flex gap-1 flex-shrink-0">
                                            <span title="Всего в партии" style="font-size:.65rem;padding:1px 4px;border-radius:3px;background:#e9ecef;color:#495057;white-space:nowrap">{{ number_format($bInit, 3, '.', '') }}</span>
                                            <span title="Доступно в партии" style="font-size:.65rem;padding:1px 4px;border-radius:3px;background:{{ $bRem > 0 ? '#cff4fc' : '#fff3cd' }};color:{{ $bRem > 0 ? '#055160' : '#664d03' }};white-space:nowrap">{{ number_format($bRem, 3, '.', '') }}</span>
                                        </div>
                                    </div>
                                @endif

                                {{-- Последняя строка: приёмщик справа --}}
                                @if($reception->receiver)
                                    <div class="d-flex justify-content-end" style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem">
                                        <span class="text-muted" style="font-size:.65rem">
                                            <i class="bi bi-person-gear me-1"></i>{{ $reception->receiver->name }}
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
                    'filter[cutter_id]', 'filter[raw_material_batch_id]',
                    'filter[product_id]', 'date_from', 'date_to'
                ].filter(k => params.get(k) && params.get(k) !== '').length
                    + params.getAll('filter[status][]').length;

                if (badge && activeFilters > 0) {
                    badge.innerHTML = `<span class="badge bg-primary rounded-pill">${activeFilters}</span>`;
                }

                const userOpened   = localStorage.getItem(STORAGE_KEY) === 'open';
                const shouldExpand = activeFilters > 0 || userOpened;

                function applyState(expanded, animate) {
                    if (expanded) {
                        collapse.style.display = '';
                        if (animate) { collapse.style.opacity = '0'; setTimeout(() => collapse.style.opacity = '', 10); }
                        chevron.className = 'bi bi-chevron-up';
                    } else {
                        if (animate) {
                            collapse.style.opacity = '0';
                            setTimeout(() => { collapse.style.display = 'none'; collapse.style.opacity = ''; }, 150);
                        } else {
                            collapse.style.display = 'none';
                        }
                        chevron.className = 'bi bi-chevron-down';
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
            document.getElementById('clear_product_filter')?.addEventListener('click', () => {
                document.getElementById('filter_product_id').value = '';
                document.getElementById('product_filter_search').value = '';
                form.submit();
            });
            document.addEventListener('product-picker:selected', () => {
                if (document.getElementById('filter_product_id')?.value) form.submit();
            });

            // МойСклад batch
            const selectAll = document.getElementById('selectAll');
            const sendBtn   = document.getElementById('sendToMoySkladBtn');
            const countSpan = document.getElementById('selectedCount');

            function updateCount() {
                const checked = document.querySelectorAll('.reception-checkbox:checked');
                const n = checked.length;
                if (countSpan) countSpan.textContent = n;
                if (sendBtn)   sendBtn.disabled = n === 0;
                if (selectAll) {
                    const all = document.querySelectorAll('.reception-checkbox');
                    selectAll.checked       = n > 0 && n === all.length;
                    selectAll.indeterminate = n > 0 && n < all.length;
                }
            }

            document.querySelectorAll('.reception-checkbox').forEach(cb =>
                cb.addEventListener('change', updateCount));
            selectAll?.addEventListener('change', function () {
                document.querySelectorAll('.reception-checkbox').forEach(cb => cb.checked = this.checked);
                updateCount();
            });
            sendBtn?.addEventListener('click', function () {
                const checked = document.querySelectorAll('.reception-checkbox:checked');
                if (!checked.length || !confirm(`Отправить ${checked.length} приёмок в МойСклад?`)) return;
                sendBtn.disabled = true;
                sendBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Отправка...';
                fetch('{{ route("stone-receptions.batch.send-to-processing") }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ reception_ids: Array.from(checked).map(cb => cb.value) })
                })
                    .then(r => r.json())
                    .then(data => {
                        alert(data.success ? data.message : 'Ошибка: ' + data.message);
                        if (data.success) window.location.reload();
                        else { sendBtn.disabled = false; updateCount(); }
                    })
                    .catch(() => { alert('Ошибка запроса'); sendBtn.disabled = false; updateCount(); });
            });

            updateCount();
        });
    </script>
@endpush
