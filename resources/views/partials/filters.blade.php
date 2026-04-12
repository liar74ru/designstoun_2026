{{--
    Универсальный блок фильтров.
    Параметры:
      $filterCutters      — Collection|null  (null = скрыть поле)
      $cutterParam        — string: 'cutter_id' | 'current_worker_id'
      $filterRawProducts  — Collection|null  (null = скрыть поле)
      $rawProductParam    — string: 'raw_product_id' | 'raw_material_product_id' | 'product_id'
      $filterProducts     — Collection|null  (null = скрыть product-picker)
      $showStatus         — false | 'multi' | 'single'
      $statusOptions      — array [ value => label ] (для multi и single)
--}}
@php
    $cutterParam   = $cutterParam   ?? 'cutter_id';
    $statusOptions = $statusOptions ?? [];
    $filterCount   = ($filterCutters    ? 1 : 0)
                   + ($filterRawProducts ? 1 : 0)
                   + ($filterProducts    ? 1 : 0)
                   + ($showStatus !== false ? 1 : 0);
    $colClass      = $filterCount >= 4 ? 'col-lg-3' : ($filterCount === 3 ? 'col-lg-4' : 'col-lg-6');
    $rawValue      = request()->input("filter.$rawProductParam", '');
    $rawLabel      = $filterRawProducts ? ($filterRawProducts->firstWhere('id', $rawValue)?->name ?? '') : '';
    $pickerValue   = request('filter.product_id', '');
    $pickerLabel   = $filterProducts ? ($filterProducts->firstWhere('id', $pickerValue)?->name ?? '') : '';
@endphp

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
                           class="form-control" style="border-radius:.4rem" value="{{ request('date_from') }}">
                </div>
                <div class="col-6 col-sm-auto">
                    <label class="form-label small text-muted mb-1">По</label>
                    <input type="date" name="date_to" id="date_to"
                           class="form-control" style="border-radius:.4rem" value="{{ request('date_to') }}">
                </div>
            </div>

            {{-- Фильтры --}}
            <div class="row g-2">

                {{-- Пильщик --}}
                @if($filterCutters)
                <div class="col-12 col-sm-6 {{ $colClass }}">
                    <label class="form-label small text-muted mb-1">Пильщик</label>
                    <select name="filter[{{ $cutterParam }}]" class="form-select" style="border-radius:.4rem">
                        <option value="">Все пильщики</option>
                        @foreach($filterCutters as $cutter)
                            <option value="{{ $cutter->id }}"
                                {{ request("filter.$cutterParam") == $cutter->id ? 'selected' : '' }}>
                                {{ $cutter->name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif

                {{-- Сырьё --}}
                @if($filterRawProducts)
                <div class="col-12 col-sm-6 {{ $colClass }}">
                    <label class="form-label small text-muted mb-1">Сырьё</label>
                    @include('partials.product-picker', [
                        'id'          => 'filter_raw',
                        'name'        => 'filter[' . $rawProductParam . ']',
                        'value'       => $rawValue,
                        'label'       => $rawLabel,
                        'placeholder' => 'Введите вид сырья...',
                        'allowedIds'  => $filterRawProducts->pluck('id')->all(),
                        'showTree'    => false,
                        'showClear'   => (bool) $rawValue,
                    ])
                </div>
                @endif

                {{-- Продукт (product-picker) --}}
                @if($filterProducts)
                <div class="col-12 col-sm-6 {{ $colClass }}">
                    <label class="form-label small text-muted mb-1">Продукт</label>
                    @include('partials.product-picker', [
                        'id'          => 'filter_product',
                        'name'        => 'filter[product_id]',
                        'value'       => $pickerValue,
                        'label'       => $pickerLabel,
                        'placeholder' => 'Введите название...',
                        'allowedIds'  => $filterProducts->pluck('id')->all(),
                        'showTree'    => true,
                        'showClear'   => (bool) $pickerValue,
                    ])
                </div>
                @endif

                {{-- Статус: чекбоксы (multi) --}}
                @if($showStatus === 'multi')
                <div class="col-12 col-sm-6 {{ $colClass }}">
                    <label class="form-label small text-muted mb-1">Статус</label>
                    <div class="d-flex flex-wrap gap-2 mt-1">
                        @foreach($statusOptions as $val => $lbl)
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

                {{-- Статус: одиночный select (single) --}}
                @elseif($showStatus === 'single')
                <div class="col-12 col-sm-6 {{ $colClass }}">
                    <label class="form-label small text-muted mb-1">Статус</label>
                    <select name="filter[status]" class="form-select" style="border-radius:.4rem">
                        <option value="">Все</option>
                        @foreach($statusOptions as $val => $lbl)
                            <option value="{{ $val }}"
                                {{ request('filter.status') == $val ? 'selected' : '' }}>{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>
                @endif

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

@push('scripts')
<script>
(function () {
    const STORAGE_KEY = 'filter_collapsed_' + window.location.pathname.replace(/\//g, '_');
    const collapse = document.getElementById('filter-collapse');
    const chevron  = document.getElementById('filter-chevron');
    const toggle   = document.getElementById('filter-toggle');
    const badge    = document.getElementById('filter-active-badge');
    const form     = document.getElementById('filter-form');
    const dateFrom = document.getElementById('date_from');
    const dateTo   = document.getElementById('date_to');

    const params = new URLSearchParams(window.location.search);
    const filterKeys = [
        'filter[{{ $cutterParam }}]',
        'filter[{{ $rawProductParam }}]',
        @if($filterProducts) 'filter[product_id]', @endif
        'date_from', 'date_to'
    ];
    const activeFilters = filterKeys.filter(k => params.get(k) && params.get(k) !== '').length
        @if($showStatus === 'multi') + params.getAll('filter[status][]').length @endif
        @if($showStatus === 'single') + (params.get('filter[status]') && params.get('filter[status]') !== '' ? 1 : 0) @endif;

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

    form.querySelectorAll('.product-picker-clear').forEach(btn => {
        btn.addEventListener('click', () => { form.submit(); });
    });

    document.addEventListener('product-picker:selected', () => {
        const pickers = form.querySelectorAll('.product-picker-row input[type="hidden"]');
        if ([...pickers].some(h => h.value)) form.submit();
    });
})();
</script>
@endpush
