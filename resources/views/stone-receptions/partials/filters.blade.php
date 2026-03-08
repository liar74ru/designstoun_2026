{{--
    Партиал фильтров.
    Ожидает: $filterCutters, $filterBatches, $filterProducts
    $showStatus (bool) — показывать чекбоксы статуса (только на странице партий)
--}}
<form method="GET" id="filter-form" class="card shadow-sm mb-4">
    <div class="card-body">

        {{-- Быстрые кнопки периода --}}
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
            {{-- Пильщик --}}
            <div class="col-sm-6 col-lg-3">
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

            {{-- Сырьё --}}
            <div class="col-sm-6 col-lg-3">
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

            {{-- Продукт --}}
            <div class="col-sm-6 col-lg-3">
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

                {{-- Модальное дерево --}}
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
            </div>

            {{-- Статус — только для страницы партий --}}
            @if(!empty($showStatus))
                <div class="col-sm-6 col-lg-3">
                    <label class="form-label small text-muted mb-1">Статус</label>
                    <div class="d-flex flex-column gap-1 mt-1">
                        @foreach(['active' => 'Активна', 'processed' => 'Обработана', 'error' => 'Ошибка'] as $val => $lbl)
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
            @endif
        </div>
    </div>

    <div class="card-footer bg-white d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-funnel"></i> Применить
        </button>
        <a href="{{ url()->current() }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-x-circle"></i> Сбросить
        </a>
    </div>
</form>
