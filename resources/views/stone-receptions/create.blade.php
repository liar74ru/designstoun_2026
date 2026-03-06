@extends('layouts.app')

@section('title', 'Новая приёмка')

@section('content')
    <div class="container py-4">
        <div class="row g-4">

            {{-- ═══════════════════════ ФОРМА ═══════════════════════ --}}
            <div class="col-lg-7">
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">➕ Новая приёмка</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('stone-receptions.store') }}" id="receptionForm">
                            @csrf

                            {{-- Ошибки --}}
                            @if($errors->any())
                                <div class="alert alert-danger py-2">
                                    @foreach($errors->all() as $error)
                                        <div class="small">{{ $error }}</div>
                                    @endforeach
                                </div>
                            @endif

                            {{-- Пильщик --}}
                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    Пильщик <span class="text-danger">*</span>
                                </label>
                                <select name="cutter_id" id="cutterSelect"
                                        class="form-select @error('cutter_id') is-invalid @enderror">
                                    <option value="">— Выберите пильщика —</option>
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

                            {{-- Приёмщик --}}
                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    Приёмщик <span class="text-danger">*</span>
                                </label>
                                <select name="receiver_id"
                                        class="form-select @error('receiver_id') is-invalid @enderror" required>
                                    <option value="">— Выберите приёмщика —</option>
                                    @foreach($masterWorkers as $worker)
                                        <option value="{{ $worker->id }}"
                                            {{ old('receiver_id', session('copy_data.receiver_id')) == $worker->id ? 'selected' : '' }}>
                                            {{ $worker->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('receiver_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            {{-- Партия сырья + расход --}}
                            <div class="row g-2 mb-3">
                                <div class="col-8">
                                    <label class="form-label fw-semibold">
                                        Партия сырья <span class="text-danger">*</span>
                                    </label>
                                    <select name="raw_material_batch_id" id="batchSelect"
                                            class="form-select @error('raw_material_batch_id') is-invalid @enderror"
                                            required>
                                        <option value="">
                                            {{ request('cutter_id') ? '— Выберите партию —' : '— Сначала выберите пильщика —' }}
                                        </option>
                                        {{-- Начальные данные (если пильщик уже выбран через URL) --}}
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
                                <div class="col-4">
                                    <label class="form-label fw-semibold">
                                        Расход (м³) <span class="text-danger">*</span>
                                    </label>
                                    <input type="number" step="0.001" min="0.001"
                                           name="raw_quantity_used" id="rawQtyInput"
                                           class="form-control @error('raw_quantity_used') is-invalid @enderror"
                                           value="{{ old('raw_quantity_used', 1) }}" required>
                                    <div class="form-text" id="remainingInfo"></div>
                                    @error('raw_quantity_used')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            {{-- Склад (скрытый) --}}
                            <input type="hidden" name="store_id" value="{{ $defaultStore->id }}">

                            {{-- Продукция --}}
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="form-label fw-semibold mb-0">
                                        Продукция <span class="text-danger">*</span>
                                    </label>
                                    <span class="text-muted small">
                                    Итого: <strong id="totalQty">0</strong> м²
                                </span>
                                </div>

                                <div id="productsContainer">
                                    {{-- Строки добавляются JS-ом --}}
                                </div>

                                <button type="button" class="btn btn-sm btn-outline-primary mt-1"
                                        id="addProductBtn">
                                    <i class="bi bi-plus-circle"></i> Добавить продукт
                                </button>
                            </div>

                            {{-- Примечания --}}
                            <div class="mb-3">
                                <label class="form-label">Примечания</label>
                                <textarea name="notes" class="form-control" rows="2"
                                >{{ old('notes', session('copy_data.notes')) }}</textarea>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Сохранить приёмку
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            {{-- ═══════════════════════ ПОСЛЕДНИЕ ПРИЁМКИ ═══════════════════════ --}}
            <div class="col-lg-5">
                <div class="card shadow-sm">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">📋 Последние приёмки</h5>
                        <span class="badge bg-secondary">{{ $lastReceptions->total() }}</span>
                    </div>
                    <div class="list-group list-group-flush">
                        @forelse($lastReceptions as $reception)
                            <div class="list-group-item px-3 py-2">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1 me-2">
                                        {{-- Шапка приёмки --}}
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <span class="fw-semibold small">#{{ $reception->id }}</span>
                                            <span class="badge bg-primary bg-opacity-10 text-primary small">
                                            {{ number_format($reception->total_quantity, 2) }} м²
                                        </span>
                                            <span class="text-muted" style="font-size:.75rem">
                                            {{ $reception->created_at->format('d.m H:i') }}
                                        </span>
                                        </div>
                                        {{-- Продукты --}}
                                        @foreach($reception->items as $item)
                                            <div class="text-muted" style="font-size:.78rem">
                                                {{ $item->product->name }}
                                                <span class="text-dark">× {{ number_format($item->quantity, 2) }}</span>
                                            </div>
                                        @endforeach
                                        {{-- Пильщик --}}
                                        @if($reception->cutter)
                                            <div class="text-muted mt-1" style="font-size:.75rem">
                                                <i class="bi bi-tools"></i> {{ $reception->cutter->name }}
                                            </div>
                                        @endif
                                    </div>

                                    {{-- Кнопка копирования продуктов --}}
                                    <form action="{{ route('stone-receptions.copy', $reception) }}"
                                          method="POST" class="flex-shrink-0">
                                        @csrf
                                        <input type="hidden" name="cutter_id" value="{{ request('cutter_id') }}">
                                        <input type="hidden" name="raw_material_batch_id"
                                               value="{{ request('raw_material_batch_id') }}">
                                        <button type="submit"
                                                class="btn btn-sm btn-outline-secondary"
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

                    {{-- Пагинация --}}
                    @if($lastReceptions->hasPages())
                        <div class="card-footer py-2">
                            {{ $lastReceptions->links('pagination::bootstrap-5') }}
                        </div>
                    @endif
                </div>
            </div>

        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {

            // ── Данные ──────────────────────────────────────────────────────────────
            const cutterSelect  = document.getElementById('cutterSelect');
            const batchSelect   = document.getElementById('batchSelect');
            const rawQtyInput   = document.getElementById('rawQtyInput');
            const remainingInfo = document.getElementById('remainingInfo');
            const container     = document.getElementById('productsContainer');
            const totalQtyEl    = document.getElementById('totalQty');
            const addBtn        = document.getElementById('addProductBtn');

            // Скопированные продукты из сессии (если была нажата кнопка "копировать")
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
            function updateRemaining() {
                const opt = batchSelect.options[batchSelect.selectedIndex];
                if (opt?.value) {
                    const rem = parseFloat(opt.dataset.remaining) || 0;
                    remainingInfo.textContent = `Доступно: ${rem.toFixed(2)} м³`;
                    remainingInfo.className = parseFloat(rawQtyInput.value) > rem
                        ? 'form-text text-danger'
                        : 'form-text text-info';
                } else {
                    remainingInfo.textContent = '';
                }
            }

            batchSelect.addEventListener('change', updateRemaining);
            rawQtyInput.addEventListener('input', updateRemaining);
            updateRemaining();

            // ── Итого по продуктам ───────────────────────────────────────────────────
            function updateTotal() {
                let sum = 0;
                container.querySelectorAll('.product-picker-qty').forEach(el => {
                    sum += parseFloat(el.value) || 0;
                });
                totalQtyEl.textContent = sum.toFixed(2);
            }

            document.addEventListener('product-picker:selected', updateTotal);
            document.addEventListener('product-picker:removed',  updateTotal);
            container.addEventListener('input', e => {
                if (e.target.classList.contains('product-picker-qty')) updateTotal();
            });

            // ── Добавить строку продукта ─────────────────────────────────────────────
            function addRow(productId = '', productLabel = '', quantity = '') {
                const tpl = document.getElementById('pickerRowTemplate');
                const clone = tpl.content.cloneNode(true);

                // Заменяем плейсхолдер индекса
                clone.querySelectorAll('[data-tpl-index]').forEach(el => {
                    ['id','name','for','data-hidden-id','data-search-id','data-modal'].forEach(attr => {
                        if (el.hasAttribute(attr)) {
                            el.setAttribute(attr, el.getAttribute(attr).replace('__IDX__', rowIndex));
                        }
                    });
                });

                // Устанавливаем значения для копии
                const searchInput = clone.querySelector('.product-picker-search');
                const hiddenInput = clone.querySelector('input[type="hidden"][name*="product_id"]');
                const qtyInput    = clone.querySelector('.product-picker-qty');

                if (searchInput) searchInput.value = productLabel;
                if (hiddenInput) hiddenInput.value  = productId;
                if (qtyInput)    qtyInput.value     = quantity;

                // Сохраняем ссылку на строку ДО того как fragment растворится в DOM
                const row = clone.querySelector('.product-picker-row');

                container.appendChild(clone);

                if (window.ProductPicker) window.ProductPicker.initRow(row);

                rowIndex++;
                updateTotal();
            }

            addBtn.addEventListener('click', () => addRow());

            // ── Инициализация: копированные или пустая строка ────────────────────────
            if (copiedProducts.length > 0) {
                // Нам нужны названия продуктов — они есть в дереве, подождём загрузки
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

    {{-- Шаблон строки продукта (используется JS через cloneNode) --}}
    <template id="pickerRowTemplate">
        <div class="product-picker-row d-flex gap-2 align-items-start mb-2" data-tpl-index="__IDX__">
            <div class="flex-grow-1 position-relative">
                <div class="input-group">
                    <input type="text"
                           id="search___IDX__"
                           data-tpl-index="__IDX__"
                           class="form-control product-picker-search"
                           placeholder="Введите название продукта..."
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

            <input type="number"
                   id="qty___IDX__"
                   name="products[__IDX__][quantity]"
                   class="form-control product-picker-qty"
                   style="width:100px"
                   placeholder="м²"
                   step="0.001" min="0.001"
                   required>

            <input type="hidden"
                   id="pid___IDX__"
                   name="products[__IDX__][product_id]"
                   data-tpl-index="__IDX__">

            <button type="button"
                    class="btn btn-outline-danger product-picker-remove"
                    title="Удалить">
                <i class="bi bi-x-lg"></i>
            </button>

            {{-- Модальное окно дерева --}}
            <div class="modal fade" id="modal___IDX__" tabindex="-1" data-tpl-index="__IDX__">
                <div class="modal-dialog modal-lg">
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
