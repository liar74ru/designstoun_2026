@extends('layouts.app')

@section('title', 'Редактирование приёмки #{{ $stoneReception->id }}')

@section('content')
    <div class="container py-3 py-md-4">

        <x-page-header
            title="✏️ Редактирование приёмки #{{ $stoneReception->id }}"
            mobile-title="✏️ Приёмка #{{ $stoneReception->id }}"
            back-url="{{ route('stone-receptions.logs') }}"
        />

        @if($errors->any())
            <div class="alert alert-danger py-2">
                <ul class="mb-0 small">
                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <div class="row g-3">
            <div class="col-12 col-lg-8">

                {{-- ══ ФОРМА ══ --}}
                <div class="card shadow-sm mb-3">
                    <div class="info-block-body">
                        <form method="POST" action="{{ route('stone-receptions.update', $stoneReception) }}" id="receptionForm">
                            @csrf
                            @method('PUT')

                            {{-- ── БЛОК 1: Участники и партия (сворачиваемый) ── --}}
                            <div class="rounded border border-secondary border-opacity-25 mb-2">
                                <div class="d-flex justify-content-between align-items-center px-2 py-1"
                                     style="cursor:pointer" id="peopleToggle" role="button">
                                    <span class="text-muted" style="font-size:.7rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase">
                                        <i class="bi bi-people me-1"></i>Участники и партия
                                    </span>
                                    <i class="bi bi-chevron-down" id="peopleChevron" style="font-size:.7rem"></i>
                                </div>
                                <div id="peopleBody" style="display:none">
                                    <div style="padding:.25rem .4rem .35rem">
                                        <div class="row g-1 mb-1">
                                            <div class="col-6">
                                                <label class="form-label small text-muted mb-0" style="font-size:.7rem">Приёмщик</label>
                                                <input type="text" class="form-control form-control-sm bg-light"
                                                       style="font-size:.8rem" value="{{ $stoneReception->receiver->name ?? '—' }}" readonly>
                                                <input type="hidden" name="receiver_id" value="{{ $stoneReception->receiver_id }}">
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label small text-muted mb-0" style="font-size:.7rem">Пильщик</label>
                                                <input type="text" class="form-control form-control-sm bg-light"
                                                       style="font-size:.8rem" value="{{ $stoneReception->cutter->name ?? '— Не указан —' }}" readonly>
                                                <input type="hidden" name="cutter_id" value="{{ $stoneReception->cutter_id }}">
                                            </div>
                                        </div>
                                        <div>
                                            <label class="form-label small text-muted mb-0" style="font-size:.7rem">Партия сырья</label>
                                            <input type="text" class="form-control form-control-sm bg-light"
                                                   style="font-size:.8rem"
                                                   value="{{ $stoneReception->rawMaterialBatch->product->name ?? '—' }}{{ $stoneReception->rawMaterialBatch?->batch_number ? ' №'.$stoneReception->rawMaterialBatch->batch_number : '' }}"
                                                   readonly>
                                            <input type="hidden"
                                                   name="raw_material_batch_id"
                                                   id="raw_material_batch_id"
                                                   value="{{ $stoneReception->raw_material_batch_id }}"
                                                   data-remaining="{{ (float)($stoneReception->rawMaterialBatch?->remaining_quantity ?? 0) }}"
                                                   data-product-sku="{{ $stoneReception->rawMaterialBatch?->product?->sku ?? '' }}">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- ── БЛОК 2: Склад (сворачиваемый) ── --}}
                            <div class="rounded border border-secondary border-opacity-25 mb-2">
                                <div class="d-flex justify-content-between align-items-center px-2 py-1"
                                     style="cursor:pointer" id="storeToggle" role="button">
                                    <span class="text-muted" style="font-size:.7rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase">
                                        <i class="bi bi-building me-1"></i>Склад
                                    </span>
                                    <i class="bi bi-chevron-down" id="storeChevron" style="font-size:.7rem"></i>
                                </div>
                                <div id="storeBody" style="display:none">
                                    <div style="padding:.25rem .4rem .35rem">
                                        @if(env('DEFAULT_STORE_ID'))
                                            <input type="text" class="form-control form-control-sm" style="font-size:.8rem"
                                                   value="{{ $defaultStore->name ?? 'Склад по умолчанию' }}" readonly>
                                            <input type="hidden" name="store_id" value="{{ $defaultStore->id }}">
                                        @else
                                            <select name="store_id" class="form-select form-select-sm @error('store_id') is-invalid @enderror" required>
                                                <option value="">— Выберите склад —</option>
                                                @foreach($stores as $store)
                                                    <option value="{{ $store->id }}" {{ old('store_id', $stoneReception->store_id) == $store->id ? 'selected' : '' }}>
                                                        {{ $store->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            @error('store_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        @endif
                                    </div>
                                </div>
                            </div>

                            {{-- ── БЛОК 3: Расход сырья ── --}}
                            <div class="rounded border border-secondary border-opacity-25 mb-2">
                                <div class="px-2 py-1 border-bottom border-secondary border-opacity-25">
                                    <span class="text-muted" style="font-size:.7rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase">
                                        🪵 Расход сырья
                                    </span>
                                </div>
                                <div style="padding:.25rem .4rem .35rem">

                                    {{-- Десктоп --}}
                                    <div class="d-none d-sm-block">
                                        <label class="form-label small mb-1">
                                            Изменение <span class="text-muted fw-normal">(м³, можно «−»)</span>
                                        </label>
                                        <div class="d-flex align-items-center gap-2">
                                            <input type="number"
                                                   name="raw_quantity_delta"
                                                   id="raw_quantity_delta"
                                                   class="form-control form-control-sm @error('raw_quantity_delta') is-invalid @enderror"
                                                   style="width:110px"
                                                   step="0.001"
                                                   placeholder="0.000"
                                                   value="{{ old('raw_quantity_delta', 0) }}">
                                            <span class="text-muted">=</span>
                                            <div class="d-flex align-items-center gap-1">
                                                <span class="fw-bold" style="font-size:.9rem">Итого:</span>
                                                <div id="raw_result" class="form-control form-control-sm bg-light fw-bold" style="min-width:110px;font-size:.95rem">
                                                    {{ number_format($stoneReception->raw_quantity_used, 3, '.', '') }} м³
                                                </div>
                                            </div>
                                        </div>
                                        @error('raw_quantity_delta')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                        <small class="d-block mt-1" style="font-size:.72rem">
                                            <span id="raw_info_before" class="text-muted"></span><span id="raw_info_sep" class="fw-bold text-dark" style="display:none"> | </span><span id="raw_info_after" class="text-info"></span>
                                        </small>
                                    </div>

                                    {{-- Мобильный --}}
                                    <div class="d-sm-none">
                                        <label class="form-label small text-muted mb-1">
                                            Изменение <span class="fw-normal">(м³, можно «−»)</span>
                                        </label>
                                        <div class="d-flex align-items-center gap-2">
                                            <input type="number"
                                                   name="raw_quantity_delta"
                                                   id="raw_quantity_delta_mobile"
                                                   class="form-control form-control-sm flex-fill"
                                                   step="0.001"
                                                   placeholder="0.000"
                                                   value="{{ old('raw_quantity_delta', 0) }}"
                                                   data-mirror="raw_quantity_delta">
                                            <span class="text-muted">=</span>
                                            <div class="text-center flex-shrink-0">
                                                <div class="fw-bold" style="font-size:.82rem">Итого:</div>
                                                <div id="raw_result_mobile" class="fw-bold text-primary" style="font-size:.95rem">
                                                    {{ number_format($stoneReception->raw_quantity_used, 3, '.', '') }} м³
                                                </div>
                                            </div>
                                        </div>
                                        <small class="d-block mt-1" style="font-size:.7rem">
                                            <span id="raw_info_before_mobile" class="text-muted"></span><span id="raw_info_sep_mobile" class="fw-bold text-dark" style="display:none"> | </span><span id="raw_info_after_mobile" class="text-info"></span>
                                        </small>
                                    </div>

                                </div>
                            </div>

                            {{-- ── БЛОК 4: Продукция ── --}}
                            <div class="rounded border border-secondary border-opacity-25 mb-2">
                                <div class="d-flex justify-content-between align-items-center px-2 py-1 border-bottom border-secondary border-opacity-25">
                                    <span class="text-muted" style="font-size:.7rem;font-weight:600;letter-spacing:.05em;text-transform:uppercase">
                                        <i class="bi bi-grid-3x3 me-1"></i>Продукция <span class="text-danger">*</span>
                                    </span>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="form-check form-check-inline mb-0" id="allCatalogWrap" style="display:none">
                                            <input class="form-check-input" type="checkbox" id="allCatalogCheck">
                                            <label class="form-check-label small text-muted" for="allCatalogCheck">весь каталог</label>
                                        </div>
                                        <span class="text-muted small">Итого: <strong id="totalProducts">0</strong> м²</span>
                                    </div>
                                </div>

                                {{-- Существующие продукты --}}
                                <div id="existing-products" style="padding:.25rem .3rem 0">
                                    @foreach($stoneReception->items as $item)
                                        @php $current = (float)$item->quantity; $idx = $loop->index; @endphp
                                        <div class="existing-row rounded border border-secondary border-opacity-25 position-relative"
                                             style="padding:.2rem .35rem;margin-bottom:.25rem"
                                             data-current="{{ $current }}">
                                            <input type="hidden" name="products[{{ $idx }}][product_id]" value="{{ $item->product_id }}">
                                            <input type="hidden" class="js-qty-out" name="products[{{ $idx }}][quantity]" value="{{ $current }}">

                                            <i class="bi bi-layers-fill position-absolute"
                                               style="top:.25rem;right:.3rem;font-size:.8rem;color:{{ $item->is_undercut ? '#198754' : '#adb5bd' }}"
                                               title="{{ $item->is_undercut ? '80% подкол применён' : 'Без подкола' }}"></i>

                                            <div style="font-size:.85rem;font-weight:600;padding-right:1.3rem;margin-bottom:.15rem">
                                                {{ $item->product->name ?? '—' }}
                                            </div>

                                            <div class="d-flex align-items-center gap-1" style="margin-bottom:.15rem">
                                                <span class="text-muted" style="font-size:.8rem;white-space:nowrap">{{ number_format($current, 3, '.', '') }}</span>
                                                <span class="text-muted" style="font-size:.8rem">+</span>
                                                <input type="number" class="form-control form-control-sm js-delta"
                                                       style="width:85px;flex-shrink:0;font-size:.82rem;padding:.15rem .3rem"
                                                       step="0.001" value="0" placeholder="0">
                                                <span class="text-muted" style="font-size:.8rem">=</span>
                                                <span class="js-result fw-semibold" style="font-size:.82rem;min-width:50px">
                                                    {{ number_format($current, 3, '.', '') }}
                                                </span>
                                                <span class="text-muted" style="font-size:.8rem">м²</span>
                                            </div>

                                        </div>
                                    @endforeach
                                </div>

                                <div id="new-products-container" style="padding:0 .3rem"></div>

                                <div style="padding:.2rem .3rem .3rem">
                                    <button type="button" class="btn btn-sm btn-outline-primary w-100" id="addProductBtn">
                                        <i class="bi bi-plus-circle"></i> Добавить продукт
                                    </button>
                                </div>
                            </div>

                            {{-- Примечания --}}
                            <div class="mb-2">
                                <label class="form-label mb-0" style="font-size:.7rem;color:#6c757d">Примечания</label>
                                <textarea name="notes" class="form-control form-control-sm @error('notes') is-invalid @enderror"
                                          rows="2" style="font-size:.8rem">{{ old('notes', $stoneReception->notes) }}</textarea>
                                @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            @if(auth()->user()?->isAdmin())
                                <div class="mb-3 p-2 border border-warning rounded bg-warning bg-opacity-10">
                                    <label class="form-label fw-semibold text-warning-emphasis mb-1">
                                        <i class="bi bi-calendar-event"></i> Дата создания
                                        <span class="badge bg-warning text-dark ms-1" style="font-size:.7rem">Только для админа</span>
                                    </label>
                                    <input type="datetime-local"
                                           name="manual_created_at"
                                           class="form-control form-control-sm"
                                           value="{{ old('manual_created_at', $stoneReception->created_at->format('Y-m-d\TH:i')) }}">
                                </div>
                            @endif

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary flex-fill">
                                    <i class="bi bi-save"></i> Сохранить
                                </button>
                                <a href="{{ route('stone-receptions.logs') }}" class="btn btn-outline-secondary">
                                    Отмена
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

            </div>

            {{-- ══ ИНФОРМАЦИЯ ══ --}}
            <div class="col-12 col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-white py-2">
                        <span class="fw-semibold small"><i class="bi bi-info-circle me-1"></i> Информация</span>
                    </div>
                    <div class="card-body p-3">
                        <dl class="row mb-0 small">
                            <dt class="col-5 text-muted">ID:</dt>
                            <dd class="col-7">{{ $stoneReception->id }}</dd>
                            <dt class="col-5 text-muted">Создано:</dt>
                            <dd class="col-7">{{ $stoneReception->created_at->format('d.m.Y H:i') }}</dd>
                            <dt class="col-5 text-muted">Обновлено:</dt>
                            <dd class="col-7">{{ $stoneReception->updated_at->format('d.m.Y H:i') }}</dd>
                            <dt class="col-5 text-muted">Позиций:</dt>
                            <dd class="col-7 mb-0">{{ $stoneReception->items->count() }}</dd>
                        </dl>
                    </div>
                </div>

                {{-- Последние приёмки по этому сырью --}}
                <div class="card shadow-sm mt-3" id="recentReceptionsCard" style="display:none">
                    <div class="card-header bg-white py-2">
                        <span class="fw-semibold small"><i class="bi bi-clock-history me-1"></i> Последние приёмки</span>
                    </div>
                    <div class="card-body p-2" id="recentReceptionsList">
                        <div class="text-center text-muted small py-2">
                            <span class="spinner-border spinner-border-sm"></span> Загрузка...
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    @vite(['resources/js/product-picker.js'])
    <script>
        document.addEventListener('DOMContentLoaded', function () {

            // ── Сворачиваемые блоки ───────────────────────────────────────────────────
            [['peopleToggle','peopleBody','peopleChevron'],
             ['storeToggle', 'storeBody', 'storeChevron']
            ].forEach(([tid, bid, cid]) => {
                const toggle  = document.getElementById(tid);
                const body    = document.getElementById(bid);
                const chevron = document.getElementById(cid);
                if (!toggle) return;
                toggle.addEventListener('click', () => {
                    const hidden = body.style.display === 'none';
                    body.style.display = hidden ? '' : 'none';
                    chevron.className  = (hidden ? 'bi bi-chevron-up' : 'bi bi-chevron-down');
                    chevron.style.fontSize = '.7rem';
                });
            });

            const currentRaw    = {{ (float)$stoneReception->raw_quantity_used }};
            const rawDeltaInput = document.getElementById('raw_quantity_delta');
            const rawResultDiv  = document.getElementById('raw_result');

            // Мобильные зеркала
            const rawDeltaMobile  = document.getElementById('raw_quantity_delta_mobile');
            const rawResultMobile = document.getElementById('raw_result_mobile');
            const rawInfoBefore  = document.getElementById('raw_info_before');
            const rawInfoSep     = document.getElementById('raw_info_sep');
            const rawInfoAfter   = document.getElementById('raw_info_after');
            const rawInfoBeforeM = document.getElementById('raw_info_before_mobile');
            const rawInfoSepM    = document.getElementById('raw_info_sep_mobile');
            const rawInfoAfterM  = document.getElementById('raw_info_after_mobile');

            function updateRaw(delta) {
                const result = Math.round((currentRaw + delta) * 1000) / 1000;

                const resultStr = result.toFixed(3) + ' м³';
                if (rawResultDiv)    { rawResultDiv.textContent = resultStr; rawResultDiv.classList.toggle('text-danger', result < 0); }
                if (rawResultMobile) { rawResultMobile.textContent = result.toFixed(3) + ' м³'; rawResultMobile.classList.toggle('text-danger', result < 0); }

                const batchInput = document.getElementById('raw_material_batch_id');
                if (batchInput?.value) {
                    const remaining   = parseFloat(batchInput.dataset.remaining) || 0;
                    const batchBefore = Math.round((remaining + currentRaw) * 1000) / 1000;
                    const batchAfter  = Math.round((remaining - delta) * 1000) / 1000;
                    const beforeText  = `Всего было: ${batchBefore.toFixed(3)} м³`;
                    const afterText   = `Доступно: ${batchAfter.toFixed(3)} м³`;
                    const afterColor  = batchAfter > 0 ? 'text-success' : 'text-danger';
                    if (rawInfoBefore)  rawInfoBefore.textContent  = beforeText;
                    if (rawInfoAfter)   { rawInfoAfter.textContent = afterText; rawInfoAfter.className = afterColor; }
                    if (rawInfoSep)     rawInfoSep.style.display   = '';
                    if (rawInfoBeforeM) rawInfoBeforeM.textContent = beforeText;
                    if (rawInfoAfterM)  { rawInfoAfterM.textContent = afterText; rawInfoAfterM.className = afterColor; }
                    if (rawInfoSepM)    rawInfoSepM.style.display  = '';
                }
            }

            rawDeltaInput?.addEventListener('input', () => {
                if (rawDeltaMobile) rawDeltaMobile.value = rawDeltaInput.value;
                updateRaw(parseFloat(rawDeltaInput.value) || 0);
            });
            rawDeltaMobile?.addEventListener('input', () => {
                if (rawDeltaInput) rawDeltaInput.value = rawDeltaMobile.value;
                updateRaw(parseFloat(rawDeltaMobile.value) || 0);
            });
            updateRaw(parseFloat(rawDeltaInput?.value) || 0);

            // ── Существующие продукты ────────────────────────────────────────────────
            document.querySelectorAll('.existing-row').forEach(row => {
                const current    = parseFloat(row.dataset.current);
                const deltaInput = row.querySelector('.js-delta');
                const resultSpan = row.querySelector('.js-result');
                const qtyOut     = row.querySelector('.js-qty-out');
                function updateRow() {
                    const delta  = parseFloat(deltaInput.value) || 0;
                    const result = Math.round((current + delta) * 1000) / 1000;
                    resultSpan.textContent = result.toFixed(3);
                    resultSpan.classList.toggle('text-danger', result < 0);
                    qtyOut.value = result;
                    updateTotal();
                }

                deltaInput.addEventListener('input', updateRow);
            });

            // ── Коэффициенты новых продуктов ────────────────────────────────────────
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

            function updateNewRowCoeff(row) {
                const undercutCb   = row.querySelector('.js-new-undercut');
                const coeffDisplay = row.querySelector('.js-new-coeff-display');
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
                if (!row || !productId || !row.classList.contains('new-product-row')) return;
                const coeffDisplay = row.querySelector('.js-new-coeff-display');
                if (coeffDisplay) {
                    const coeff = await fetchProductCoeff(productId);
                    if (coeff !== null) { coeffDisplay.dataset.baseCoeff = coeff; updateNewRowCoeff(row); }
                }
            });

            // ── Новые продукты ───────────────────────────────────────────────────────
            let newIdx = {{ $stoneReception->items->count() }};
            const newContainer = document.getElementById('new-products-container');

            newContainer.addEventListener('change', function (e) {
                if (e.target.classList.contains('js-new-undercut')) {
                    const row = e.target.closest('.new-product-row');
                    if (row) updateNewRowCoeff(row);
                }
            });

            let currentSkuPrefix = null;

            function addNewProduct(productId = '', productLabel = '', isUndercut = false) {
                const idx   = newIdx++;
                const tpl   = document.getElementById('editPickerRowTemplate');
                const clone = tpl.content.cloneNode(true);

                clone.querySelectorAll('[data-tpl-idx]').forEach(el => {
                    ['id','name','data-hidden-id','data-search-id','data-modal'].forEach(attr => {
                        if (el.hasAttribute(attr))
                            el.setAttribute(attr, el.getAttribute(attr).replace(/__IDX__/g, idx));
                    });
                });

                const row = clone.querySelector('.new-product-row');
                newContainer.appendChild(clone);

                const deltaInput = row.querySelector('.js-new-delta');
                const resultSpan = row.querySelector('.js-new-result');
                const qtyOut     = row.querySelector('.js-new-qty-out');

                deltaInput.addEventListener('input', function () {
                    const val = parseFloat(this.value) || 0;
                    resultSpan.textContent = val > 0 ? val.toFixed(3) : '—';
                    resultSpan.classList.toggle('text-muted', val <= 0);
                    qtyOut.value = val > 0 ? val : 0;
                    updateTotal();
                });

                row.querySelector('.js-remove-new').addEventListener('click', function () {
                    row.remove();
                    updateTotal();
                });

                // Наследуем текущий SKU-фильтр
                if (currentSkuPrefix && !(document.getElementById('allCatalogCheck')?.checked)) {
                    row.dataset.skuPrefix = currentSkuPrefix;
                }
                if (window.ProductPicker) window.ProductPicker.initRow(row);

                // Предзаполнение из копирования
                if (productId) {
                    const searchInput = row.querySelector('.product-picker-search');
                    const hiddenInput = row.querySelector('input[type="hidden"][name*="product_id"]');
                    if (searchInput) searchInput.value = productLabel;
                    if (hiddenInput) hiddenInput.value = productId;
                    if (isUndercut) {
                        const undercutCb = row.querySelector('.js-new-undercut');
                        if (undercutCb) undercutCb.checked = true;
                    }
                    fetchProductCoeff(productId).then(coeff => {
                        if (coeff !== null) {
                            const coeffDisplay = row.querySelector('.js-new-coeff-display');
                            if (coeffDisplay) {
                                coeffDisplay.dataset.baseCoeff = coeff;
                                updateNewRowCoeff(row);
                            }
                        }
                    });
                }
            }

            document.getElementById('addProductBtn').addEventListener('click', () => addNewProduct());

            // ── SKU-фильтр продуктов (из партии сырья) ───────────────────────────────
            const allCatalogWrap       = document.getElementById('allCatalogWrap');
            const allCatalogCheck      = document.getElementById('allCatalogCheck');
            const newProductsContainer = document.getElementById('new-products');
            const SKU_GROUP_MAP_EDIT   = { '01': '04' };

            function localDerivePrefix(rawSku) {
                if (!rawSku) return null;
                const parts = rawSku.split('-');
                if (parts.length < 2) return null;
                const out = SKU_GROUP_MAP_EDIT[parts[0]];
                return out ? `${out}-${parts[1]}` : null;
            }

            function applySkuPrefix(prefix) {
                currentSkuPrefix = prefix;
                if (newProductsContainer) {
                    newProductsContainer.querySelectorAll('.product-picker-row').forEach(row => {
                        if (prefix) row.dataset.skuPrefix = prefix;
                        else delete row.dataset.skuPrefix;
                    });
                }
                if (allCatalogWrap) allCatalogWrap.style.display = prefix ? '' : 'none';
                if (allCatalogCheck) allCatalogCheck.checked = false;
            }

            if (allCatalogCheck) {
                allCatalogCheck.addEventListener('change', function () {
                    if (!newProductsContainer) return;
                    newProductsContainer.querySelectorAll('.product-picker-row').forEach(row => {
                        if (this.checked) delete row.dataset.skuPrefix;
                        else if (currentSkuPrefix) row.dataset.skuPrefix = currentSkuPrefix;
                    });
                });
            }

            // Инициализация при загрузке страницы
            const batchHidden = document.getElementById('raw_material_batch_id');
            if (batchHidden?.dataset.productSku) {
                applySkuPrefix(localDerivePrefix(batchHidden.dataset.productSku));
            }

            // ── Последние приёмки ────────────────────────────────────────────────────
            const recentCard = document.getElementById('recentReceptionsCard');
            const recentList = document.getElementById('recentReceptionsList');
            const batchIdVal = document.getElementById('raw_material_batch_id')?.value;

            function getExistingProductIds() {
                const ids = new Set();
                document.querySelectorAll('#existing-products input[name*="[product_id]"]').forEach(el => {
                    if (el.value) ids.add(String(el.value));
                });
                newContainer.querySelectorAll('input[name*="[product_id]"]').forEach(el => {
                    if (el.value) ids.add(String(el.value));
                });
                return ids;
            }

            function renderReceptionsList(receptions) {
                if (!receptions.length) {
                    recentList.innerHTML = '<div class="text-muted small text-center py-2">Нет данных</div>';
                    return;
                }
                recentList.innerHTML = '';
                receptions.forEach(rec => {
                    const div = document.createElement('div');
                    div.className = 'border rounded p-2 mb-2';

                    const header = document.createElement('div');
                    header.className = 'd-flex justify-content-between align-items-center mb-1';
                    const copyBtn = document.createElement('button');
                    copyBtn.type = 'button';
                    copyBtn.className = 'copy-from-recent-btn btn btn-outline-primary py-0 px-1';
                    copyBtn.style.fontSize = '.72rem';
                    copyBtn.dataset.items = JSON.stringify(rec.items);
                    copyBtn.innerHTML = '<i class="bi bi-clipboard-plus"></i> Скопировать';

                    const meta = document.createElement('span');
                    meta.className = 'small text-muted';
                    meta.textContent = rec.created_at + (rec.cutter_name ? ' · ' + rec.cutter_name : '');

                    header.appendChild(meta);
                    header.appendChild(copyBtn);
                    div.appendChild(header);

                    const ul = document.createElement('ul');
                    ul.className = 'mb-0 ps-3 small text-muted';
                    rec.items.forEach(item => {
                        const li = document.createElement('li');
                        li.textContent = item.product_label + (item.is_undercut ? ' (80%)' : '');
                        ul.appendChild(li);
                    });
                    div.appendChild(ul);
                    recentList.appendChild(div);
                });
            }

            if (batchIdVal) {
                recentCard.style.display = '';
                fetch(`/api/batches/${batchIdVal}/receptions`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(r => r.ok ? r.json() : Promise.reject())
                .then(data => renderReceptionsList(data))
                .catch(() => {
                    recentList.innerHTML = '<div class="text-danger small text-center py-2">Ошибка загрузки</div>';
                });
            }

            recentList?.addEventListener('click', function (e) {
                const btn = e.target.closest('.copy-from-recent-btn');
                if (!btn) return;
                const items = JSON.parse(btn.dataset.items);
                const existing = getExistingProductIds();
                const toAdd = items.filter(p => !existing.has(String(p.product_id)));
                if (!toAdd.length) {
                    alert('Все продукты из этой приёмки уже добавлены');
                    return;
                }
                toAdd.forEach(p => addNewProduct(String(p.product_id), p.product_label, !!p.is_undercut));
            });

            // ── Итого ────────────────────────────────────────────────────────────────
            function updateTotal() {
                let total = 0;
                document.querySelectorAll('#existing-products .js-qty-out').forEach(el => {
                    total += parseFloat(el.value) || 0;
                });
                newContainer.querySelectorAll('.js-new-qty-out').forEach(el => {
                    total += parseFloat(el.value) || 0;
                });
                document.getElementById('totalProducts').textContent = total.toFixed(2);
            }

            updateTotal();

            // ── Валидация ────────────────────────────────────────────────────────────
            document.getElementById('receptionForm').addEventListener('submit', function (e) {
                const delta = parseFloat(rawDeltaInput?.value) || 0;
                if (currentRaw + delta < 0) {
                    e.preventDefault();
                    alert('Итоговый расход сырья не может быть отрицательным');
                    return;
                }
                let ok = true;
                newContainer.querySelectorAll('.new-product-row').forEach(row => {
                    const pid   = row.querySelector('input[type="hidden"][name*="product_id"]')?.value;
                    const delta = parseFloat(row.querySelector('.js-new-delta')?.value);
                    if (!pid || !delta || delta <= 0) {
                        ok = false;
                        row.classList.add('border', 'border-danger', 'rounded', 'p-1');
                    } else {
                        row.classList.remove('border', 'border-danger', 'rounded', 'p-1');
                    }
                });
                if (!ok) { e.preventDefault(); alert('Для новых продуктов: выберите продукт и укажите количество > 0'); }
            });
        });
    </script>
@endpush

{{-- Шаблон новых продуктов --}}
<template id="editPickerRowTemplate">
    <div class="new-product-row mb-2 p-2 rounded border border-secondary border-opacity-25">

        {{-- Строка 1: поиск + удалить --}}
        <div class="d-flex gap-1 align-items-start mb-1">
            <div class="flex-grow-1 position-relative">
                <div class="input-group input-group-sm">
                    <input type="text"
                           id="edit_search___IDX__"
                           data-tpl-idx="1"
                           class="form-control product-picker-search"
                           placeholder="Название продукта..."
                           autocomplete="off"
                           data-hidden-id="edit_pid___IDX__">
                    <button type="button"
                            class="btn btn-outline-secondary product-picker-tree-btn"
                            data-modal="edit_modal___IDX__"
                            data-hidden-id="edit_pid___IDX__"
                            data-search-id="edit_search___IDX__"
                            data-tpl-idx="1"
                            title="Выбрать из каталога">
                        <i class="bi bi-diagram-3"></i>
                    </button>
                </div>
                <div class="product-picker-dropdown list-group shadow-sm"
                     style="display:none;position:absolute;z-index:1000;width:100%;max-height:280px;overflow-y:auto">
                </div>
            </div>
            <button type="button"
                    class="btn btn-sm btn-outline-danger js-remove-new flex-shrink-0"
                    style="height:31px" title="Удалить">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        {{-- Строка 2: количество + подкол + коэф --}}
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <div class="d-flex align-items-center gap-1 text-muted small">
                <span>0</span><span>+</span>
                <input type="number"
                       class="form-control form-control-sm js-new-delta"
                       style="width:90px"
                       step="0.001" min="0.001"
                       placeholder="0.000">
                <span>=</span>
                <span class="js-new-result fw-semibold text-muted">—</span>
                <span>м²</span>
            </div>

            <div class="form-check mb-0 flex-shrink-0">
                <input class="form-check-input js-new-undercut"
                       type="checkbox"
                       id="edit_undercut___IDX__"
                       name="products[__IDX__][is_undercut]"
                       value="1"
                       data-tpl-idx="1">
                <label class="form-check-label small text-warning-emphasis fw-semibold"
                       for="edit_undercut___IDX__">80% подкол</label>
            </div>

            <span class="text-muted small text-nowrap">
                коэф: <span class="js-new-coeff-display fw-semibold text-dark" data-base-coeff="">—</span>
            </span>
        </div>

        <input type="hidden" id="edit_pid___IDX__" name="products[__IDX__][product_id]" data-tpl-idx="1">
        <input type="hidden" name="products[__IDX__][quantity]" class="js-new-qty-out" value="0" data-tpl-idx="1">

        <div class="modal fade" id="edit_modal___IDX__" tabindex="-1" data-tpl-idx="1">
            <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Выбрать из каталога</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" style="max-height:70vh;overflow-y:auto">
                        <input type="text" class="form-control mb-3 tree-search-input" placeholder="Поиск по каталогу...">
                        <div class="product-tree-container"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
