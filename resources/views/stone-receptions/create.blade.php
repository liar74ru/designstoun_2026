@extends('layouts.app')

@section('title', 'Новая приёмка')

@section('content')
    <div class="container py-3 py-md-4">

        <x-page-header
            title="➕ Новая приёмка"
            back-url="{{ route('stone-receptions.logs') }}"
        />

        @include('partials.alerts')

        <div class="row g-3">

            {{-- ═══════════════════════ ФОРМА ═══════════════════════ --}}
            <div class="col-12 col-lg-7">
                <div class="card shadow-sm">
                    <div class="info-block-body">
                        <form method="POST" action="{{ route('stone-receptions.store') }}" id="receptionForm">
                            @csrf

                            {{-- Ошибки --}}
                            @if($errors->any())
                                <div class="alert alert-danger py-2 mb-2">
                                    @foreach($errors->all() as $error)
                                        <div class="small">{{ $error }}</div>
                                    @endforeach
                                </div>
                            @endif

                            {{-- Блок 1: Участники --}}
                            <div class="info-block">
                                <div class="info-block-header">
                                    <span class="small fw-semibold text-muted">Участники</span>
                                </div>
                                <div class="info-block-body">
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <label class="form-label small fw-semibold mb-1">
                                                Пильщик <span class="text-danger">*</span>
                                            </label>
                                            <select name="cutter_id" id="cutterSelect"
                                                    class="form-select form-select-sm @error('cutter_id') is-invalid @enderror"
                                                    style="font-size:.8rem;padding:.18rem .35rem;border-radius:.4rem">
                                                <option value="">— пильщик —</option>
                                                @foreach($workers as $worker)
                                                    <option value="{{ $worker->id }}"
                                                        {{ old('cutter_id', request('cutter_id')) == $worker->id ? 'selected' : '' }}>
                                                        {{ $worker->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            @error('cutter_id')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small fw-semibold mb-1">
                                                Приёмщик <span class="text-danger">*</span>
                                            </label>
                                            <select name="receiver_id"
                                                    class="form-select form-select-sm @error('receiver_id') is-invalid @enderror" required
                                                    style="font-size:.8rem;padding:.18rem .35rem;border-radius:.4rem">
                                                <option value="">— приёмщик —</option>
                                                @foreach($masterWorkers as $worker)
                                                    <option value="{{ $worker->id }}"
                                                        {{ old('receiver_id', session('copy_data.receiver_id', auth()->user()->worker_id)) == $worker->id ? 'selected' : '' }}>
                                                        {{ $worker->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            @error('receiver_id')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Блок 2: Партия сырья --}}
                            <div class="info-block">
                                <div class="info-block-header">
                                    <span class="small fw-semibold text-muted">Партия сырья</span>
                                </div>
                                <div class="info-block-body">
                                    <div class="row g-2">
                                        <div class="col-8 col-sm-9">
                                            <label class="form-label small fw-semibold mb-1">
                                                Партия <span class="text-danger">*</span>
                                            </label>
                                            <select name="raw_material_batch_id" id="batchSelect"
                                                    class="form-select form-select-sm @error('raw_material_batch_id') is-invalid @enderror"
                                                    required
                                                    style="font-size:.8rem;padding:.18rem .35rem;border-radius:.4rem">
                                                <option value="">
                                                    {{ request('cutter_id') ? '— Выберите партию —' : '— Выберите пильщика —' }}
                                                </option>
                                                @foreach($filteredBatches as $batch)
                                                    <option value="{{ $batch->id }}"
                                                            data-remaining="{{ $batch->remaining_quantity }}"
                                                        {{ old('raw_material_batch_id', request('raw_material_batch_id')) == $batch->id ? 'selected' : '' }}>
                                                        {{ $batch->product->name }}
                                                        (ост: {{ number_format($batch->remaining_quantity, 2) }} м³)
                                                        @if($batch->batch_number) №{{ $batch->batch_number }} @endif
                                                    </option>
                                                @endforeach
                                            </select>
                                            @error('raw_material_batch_id')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                        <div class="col-4 col-sm-3">
                                            <label class="form-label small fw-semibold mb-1">
                                                Расход <span class="text-muted fw-normal">(м³)</span> <span class="text-danger">*</span>
                                            </label>
                                            <input type="number" step="0.001" min="0.001"
                                                   name="raw_quantity_used" id="rawQtyInput"
                                                   class="form-control form-control-sm @error('raw_quantity_used') is-invalid @enderror"
                                                   value="{{ old('raw_quantity_used', 0) }}" required>
                                            <div class="form-text" id="remainingInfo" style="font-size:.7rem"></div>
                                            @error('raw_quantity_used')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Склад (скрытый) --}}
                            <input type="hidden" name="store_id" value="{{ $defaultStore?->id }}">

                            {{-- Блок 3: Продукция --}}
                            <div class="info-block">
                                <div class="info-block-header d-flex justify-content-between align-items-center">
                                    <span class="small fw-semibold text-muted">Продукция <span class="text-danger">*</span></span>
                                    <span class="text-muted small">Итого: <strong id="totalQty">0</strong> м²</span>
                                </div>
                                <div class="info-block-body">
                                    <div id="productsContainer" style="margin-bottom:.25rem"></div>
                                    <button type="button" class="btn btn-sm btn-outline-primary mt-1"
                                            id="addProductBtn">
                                        <i class="bi bi-plus-circle"></i> Добавить продукт
                                    </button>
                                </div>
                            </div>

                            {{-- Блок 4: Примечание --}}
                            <div class="info-block">
                                <div class="info-block-header">
                                    <span class="small fw-semibold text-muted">Примечание</span>
                                </div>
                                <div class="info-block-body">
                                    <textarea name="notes" class="form-control form-control-sm" rows="2"
                                    >{{ old('notes', session('copy_data.notes')) }}</textarea>
                                </div>
                            </div>

                            @if(auth()->user()?->isAdmin())
                                <div class="mb-2 p-2 border border-warning rounded bg-warning bg-opacity-10">
                                    <label class="form-label small fw-semibold text-warning-emphasis mb-1">
                                        <i class="bi bi-calendar-event"></i> Дата создания
                                        <span class="badge bg-warning text-dark ms-1" style="font-size:.7rem">Только для админа</span>
                                    </label>
                                    <input type="datetime-local"
                                           name="manual_created_at"
                                           class="form-control form-control-sm"
                                           value="{{ old('manual_created_at') }}">
                                    <div class="form-text small">Оставьте пустым — дата установится автоматически</div>
                                </div>
                            @endif

                            <button type="submit" class="btn btn-primary btn-sm w-100 mt-1">
                                <i class="bi bi-save"></i> Сохранить приёмку
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            {{-- ═══════════════════════ ПОСЛЕДНИЕ ПРИЁМКИ ═══════════════════════ --}}
            <div class="col-12 col-lg-5">
                <div class="card shadow-sm">
                    {{-- Заголовок — на мобильном кликабельный для сворачивания --}}
                    <div class="card-header bg-white d-flex justify-content-between align-items-center py-2"
                         id="lastReceptionsToggle" style="cursor:pointer" role="button">
                        <span class="fw-semibold small">
                            <i class="bi bi-clock-history me-1"></i> Последние приёмки
                        </span>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-secondary">{{ $lastReceptions->total() }}</span>
                            <i class="bi bi-chevron-down d-md-none" id="lastReceptionsChevron"></i>
                        </div>
                    </div>

                    <div id="lastReceptionsBody">
                        <div class="list-group list-group-flush">
                            @forelse($lastReceptions as $reception)
                                <div class="list-group-item px-2 py-2">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1 me-2">
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <span class="fw-semibold small">#{{ $reception->id }}</span>
                                                <span class="badge bg-primary bg-opacity-10 text-primary" style="font-size:.7rem">
                                                    {{ number_format($reception->total_quantity, 2) }} м²
                                                </span>
                                                <span class="text-muted" style="font-size:.72rem">
                                                    {{ $reception->created_at->format('d.m H:i') }}
                                                </span>
                                            </div>
                                            @foreach($reception->items as $item)
                                                <div class="text-muted" style="font-size:.75rem">
                                                    {{ $item->product->name }}
                                                    <span class="text-dark">× {{ number_format($item->quantity, 2) }}</span>
                                                </div>
                                            @endforeach
                                            @if($reception->cutter)
                                                <div class="text-muted mt-1" style="font-size:.72rem">
                                                    <i class="bi bi-hammer me-1"></i>{{ $reception->cutter->name }}
                                                </div>
                                            @endif
                                        </div>
                                        <form action="{{ route('stone-receptions.copy', $reception) }}"
                                              method="POST" class="flex-shrink-0">
                                            @csrf
                                            <input type="hidden" name="cutter_id" value="{{ request('cutter_id') }}">
                                            <input type="hidden" name="raw_material_batch_id"
                                                   value="{{ request('raw_material_batch_id') }}">
                                            <button type="submit"
                                                    class="btn btn-sm btn-outline-secondary"
                                                    style="width:28px;height:28px;padding:0;font-size:.75rem"
                                                    title="Скопировать продукты">
                                                <i class="bi bi-copy"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            @empty
                                <div class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                                    Нет приёмок
                                </div>
                            @endforelse
                        </div>

                        @if($lastReceptions->hasPages())
                            <div class="card-footer py-2">
                                {{ $lastReceptions->links('pagination::bootstrap-5') }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>

        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {

            // ── Последние приёмки: сворачивание на мобильном ────────────────────────
            (function () {
                const toggle  = document.getElementById('lastReceptionsToggle');
                const body    = document.getElementById('lastReceptionsBody');
                const chevron = document.getElementById('lastReceptionsChevron');
                const STORAGE_KEY = 'last_receptions_open';

                function isMobile() { return window.innerWidth < 768; }

                function applyState(open) {
                    if (!isMobile()) { body.style.display = ''; return; }
                    body.style.display = open ? '' : 'none';
                    if (chevron) chevron.className = open ? 'bi bi-chevron-up d-md-none' : 'bi bi-chevron-down d-md-none';
                }

                // По умолчанию на мобильном скрыто
                applyState(localStorage.getItem(STORAGE_KEY) === 'open');

                toggle.addEventListener('click', function () {
                    if (!isMobile()) return;
                    const isHidden = body.style.display === 'none';
                    applyState(isHidden);
                    localStorage.setItem(STORAGE_KEY, isHidden ? 'open' : 'closed');
                });

                window.addEventListener('resize', () => applyState(localStorage.getItem(STORAGE_KEY) === 'open'));
            })();

            // ── Данные ──────────────────────────────────────────────────────────────
            const cutterSelect  = document.getElementById('cutterSelect');
            const batchSelect   = document.getElementById('batchSelect');
            const rawQtyInput   = document.getElementById('rawQtyInput');
            const remainingInfo = document.getElementById('remainingInfo');
            const container     = document.getElementById('productsContainer');
            const totalQtyEl    = document.getElementById('totalQty');
            const addBtn        = document.getElementById('addProductBtn');

            const copiedProducts = @json(session('copy_data.products', []));
            let rowIndex = 0;

            // ── Смена пильщика → AJAX загрузка партий ───────────────────────────────
            cutterSelect.addEventListener('change', function () {
                const cutterId = this.value;
                batchSelect.innerHTML = '<option value="">Загрузка...</option>';

                if (!cutterId) {
                    batchSelect.innerHTML = '<option value="">— Сначала выберите пильщика —</option>';
                    return;
                }

                fetch(`/api/workers/${cutterId}/batches`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(r => r.json())
                    .then(batches => {
                        if (!batches.length) {
                            batchSelect.innerHTML = '<option value="">— Нет доступных партий —</option>';
                            return;
                        }
                        batchSelect.innerHTML = '<option value="">— Выберите партию —</option>';
                        batches.forEach(b => {
                            const opt = document.createElement('option');
                            opt.value = b.id;
                            opt.dataset.remaining = b.remaining_quantity;
                            opt.textContent = b.label;
                            batchSelect.appendChild(opt);
                        });
                    });
            });

            // ── Остаток партии ───────────────────────────────────────────────────────
            function updateRemainingIndicator() {
                const opt = batchSelect.options[batchSelect.selectedIndex];
                if (opt?.value) {
                    const rem = parseFloat(opt.dataset.remaining) || 0;
                    remainingInfo.textContent = `Доступно: ${rem.toFixed(3)} м³`;
                    remainingInfo.className = parseFloat(rawQtyInput.value) > rem
                        ? 'form-text text-danger'
                        : 'form-text text-info';
                } else {
                    remainingInfo.textContent = '';
                }
            }

            batchSelect.addEventListener('change', function () {
                const opt = batchSelect.options[batchSelect.selectedIndex];
                if (opt?.value) {
                    const rem = parseFloat(opt.dataset.remaining) || 0;
                    rawQtyInput.value = rem.toFixed(3);
                }
                updateRemainingIndicator();
            });
            rawQtyInput.addEventListener('input', updateRemainingIndicator);
            updateRemainingIndicator();

            // ── Данные продуктов (коэффициенты) ─────────────────────────────────────
            const productCoeffCache = {};

            async function fetchProductCoeff(productId) {
                if (!productId) return null;
                if (productCoeffCache[productId] !== undefined) return productCoeffCache[productId];
                try {
                    const res = await fetch(`/api/products/${productId}/coeff`);
                    if (!res.ok) return null;
                    const data = await res.json();
                    productCoeffCache[productId] = data.prod_cost_coeff ?? 0;
                    return productCoeffCache[productId];
                } catch { return null; }
            }

            function updateRowCoeff(row) {
                const coeffDisplay = row.querySelector('.coeff-display');
                const undercutCb   = row.querySelector('.undercut-checkbox');
                if (!coeffDisplay) return;

                const baseCoeff = parseFloat(coeffDisplay.dataset.baseCoeff);
                if (isNaN(baseCoeff)) return;

                const isUndercut = undercutCb?.checked || false;
                const effective  = isUndercut ? baseCoeff - 1.5 : baseCoeff;
                coeffDisplay.textContent = isUndercut
                    ? `${baseCoeff.toFixed(4)} − 1.5 = ${effective.toFixed(4)}`
                    : baseCoeff.toFixed(4);
                coeffDisplay.classList.toggle('text-warning-emphasis', isUndercut);
                coeffDisplay.classList.toggle('text-dark', !isUndercut);
            }

            document.addEventListener('product-picker:selected', async function (e) {
                const row       = e.detail?.row;
                const productId = e.detail?.productId;
                if (!row || !productId) { updateTotal(); return; }

                const coeffDisplay = row.querySelector('.coeff-display');
                if (coeffDisplay) {
                    const coeff = await fetchProductCoeff(productId);
                    if (coeff !== null) {
                        coeffDisplay.dataset.baseCoeff = coeff;
                        updateRowCoeff(row);
                    }
                }
                updateTotal();
            });

            container.addEventListener('change', function (e) {
                if (e.target.classList.contains('undercut-checkbox')) {
                    const row = e.target.closest('.product-picker-row');
                    if (row) updateRowCoeff(row);
                }
            });

            function updateTotal() {
                let sum = 0;
                container.querySelectorAll('.product-picker-qty').forEach(el => {
                    sum += parseFloat(el.value) || 0;
                });
                totalQtyEl.textContent = sum.toFixed(2);
            }

            document.addEventListener('product-picker:removed', updateTotal);
            container.addEventListener('input', e => {
                if (e.target.classList.contains('product-picker-qty')) updateTotal();
            });

            // ── Добавить строку продукта ─────────────────────────────────────────────
            function addRow(productId = '', productLabel = '', quantity = '') {
                const tpl   = document.getElementById('pickerRowTemplate');
                const clone = tpl.content.cloneNode(true);

                clone.querySelectorAll('[data-tpl-index]').forEach(el => {
                    ['id','name','for','data-hidden-id','data-search-id','data-modal'].forEach(attr => {
                        if (el.hasAttribute(attr)) {
                            el.setAttribute(attr, el.getAttribute(attr).replace('__IDX__', rowIndex));
                        }
                    });
                });

                const searchInput = clone.querySelector('.product-picker-search');
                const hiddenInput = clone.querySelector('input[type="hidden"][name*="product_id"]');
                const qtyInput    = clone.querySelector('.product-picker-qty');

                if (searchInput) searchInput.value = productLabel;
                if (hiddenInput) hiddenInput.value  = productId;
                if (qtyInput)    qtyInput.value     = quantity;

                const row = clone.querySelector('.product-picker-row');
                container.appendChild(clone);
                if (window.ProductPicker) window.ProductPicker.initRow(row);

                rowIndex++;
                updateTotal();
            }

            addBtn.addEventListener('click', () => addRow());

            if (copiedProducts.length > 0) {
                if (window.ProductPicker) {
                    window.ProductPicker.fetchTree().then(tree => {
                        const flat = {};
                        function flatMap(groups) {
                            groups.forEach(g => {
                                (g.products || []).forEach(p => { flat[p.id] = p.label; });
                                if (g.children?.length) flatMap(g.children);
                            });
                        }
                        flatMap(tree);
                        copiedProducts.forEach(p => addRow(p.product_id, flat[p.product_id] || '', p.quantity));
                    });
                }
            } else {
                addRow();
            }

            // ── Валидация перед отправкой ────────────────────────────────────────────
            document.getElementById('receptionForm').addEventListener('submit', function (e) {
                let ok = true;
                const rows = container.querySelectorAll('.product-picker-row');

                if (!rows.length) {
                    alert('Добавьте хотя бы один продукт');
                    e.preventDefault(); return;
                }

                rows.forEach(row => {
                    const pid = row.querySelector('input[type="hidden"][name*="product_id"]')?.value;
                    const qty = parseFloat(row.querySelector('.product-picker-qty')?.value);
                    if (!pid || !qty || qty <= 0) {
                        ok = false;
                        row.classList.add('border', 'border-danger', 'rounded');
                    } else {
                        row.classList.remove('border', 'border-danger', 'rounded');
                    }
                });

                if (!ok) { alert('Заполните все поля продуктов'); e.preventDefault(); return; }

                const opt = batchSelect.options[batchSelect.selectedIndex];
                if (opt?.value) {
                    const rem  = parseFloat(opt.dataset.remaining) || 0;
                    const used = parseFloat(rawQtyInput.value) || 0;
                    if (used > rem) {
                        alert('Расход сырья превышает остаток в партии');
                        e.preventDefault();
                    }
                }
            });
        });
    </script>

    {{-- Шаблон строки продукта --}}
    <template id="pickerRowTemplate">
        <div class="product-picker-row" data-tpl-index="__IDX__"
             style="padding:.35rem 0;border-bottom:1px solid #f0f0f0">

            {{-- Строка 1: поиск + кнопка дерева + удалить --}}
            <div class="d-flex gap-1 align-items-start mb-1">
                <div class="flex-grow-1 position-relative">
                    <div class="input-group input-group-sm">
                        <input type="text"
                               id="search___IDX__"
                               data-tpl-index="__IDX__"
                               class="form-control product-picker-search"
                               placeholder="Название продукта..."
                               autocomplete="off"
                               data-hidden-id="pid___IDX__"
                               required>
                        <button type="button"
                                class="btn btn-outline-secondary product-picker-tree-btn"
                                data-modal="modal___IDX__"
                                data-hidden-id="pid___IDX__"
                                data-search-id="search___IDX__"
                                data-tpl-index="__IDX__"
                                title="Выбрать из каталога">
                            <i class="bi bi-diagram-3"></i>
                        </button>
                    </div>
                    <div class="product-picker-dropdown list-group shadow-sm"
                         id="drop___IDX__"
                         style="display:none;position:absolute;z-index:1000;width:100%;max-height:280px;overflow-y:auto">
                    </div>
                </div>
                <button type="button"
                        class="btn btn-sm btn-outline-danger product-picker-remove flex-shrink-0"
                        style="height:31px"
                        title="Удалить">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            {{-- Строка 2: количество + подкол + коэфф --}}
            <div class="d-flex gap-2 align-items-center">
                <div class="input-group input-group-sm" style="width:130px;flex-shrink:0">
                    <span class="input-group-text" style="font-size:.75rem">м²</span>
                    <input type="number"
                           id="qty___IDX__"
                           name="products[__IDX__][quantity]"
                           class="form-control product-picker-qty"
                           placeholder="0.000"
                           step="0.001" min="0.001"
                           data-tpl-index="__IDX__"
                           required>
                </div>

                <div class="form-check mb-0 flex-shrink-0">
                    <input class="form-check-input undercut-checkbox"
                           type="checkbox"
                           id="undercut___IDX__"
                           name="products[__IDX__][is_undercut]"
                           value="1"
                           data-tpl-index="__IDX__">
                    <label class="form-check-label small text-warning-emphasis fw-semibold"
                           for="undercut___IDX__"
                           title="Снижает коэффициент на 1.5">
                        80% подкол
                    </label>
                </div>

                <span class="text-muted small text-nowrap">
                    коэф: <span class="coeff-display fw-semibold text-dark"
                                data-base-coeff=""
                                data-tpl-index="__IDX__">—</span>
                </span>

                <input type="hidden"
                       id="pid___IDX__"
                       name="products[__IDX__][product_id]"
                       data-tpl-index="__IDX__">
            </div>

            {{-- Модальное окно дерева --}}
            <div class="modal fade" id="modal___IDX__" tabindex="-1" data-tpl-index="__IDX__">
                <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Выбрать из каталога</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body" style="max-height:70vh;overflow-y:auto">
                            <input type="text"
                                   class="form-control mb-3 tree-search-input"
                                   placeholder="Поиск по каталогу...">
                            <div class="product-tree-container"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>

    @vite(['resources/js/product-picker.js'])
@endpush
