@extends('layouts.app')

@section('title', 'Редактирование приемки')

@section('content')
    <div class="container py-4">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2 mb-0">✏️ Редактирование приемки #{{ $stoneReception->id }}</h1>
            <a href="{{ route('stone-receptions.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> К списку
            </a>
        </div>

        @if($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <div class="row justify-content-center">
            <div class="col-md-10">
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">📝 Данные приемки</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('stone-receptions.update', $stoneReception) }}" id="receptionForm">
                            @csrf
                            @method('PUT')

                            {{-- Приёмщик / Пильщик --}}
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Приемщик <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control bg-light"
                                           value="{{ $stoneReception->receiver->name ?? '—' }}" readonly>
                                    <input type="hidden" name="receiver_id" value="{{ $stoneReception->receiver_id }}">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Пильщик</label>
                                    <input type="text" class="form-control bg-light"
                                           value="{{ $stoneReception->cutter->name ?? '— Не указан —' }}" readonly>
                                    <input type="hidden" name="cutter_id" value="{{ $stoneReception->cutter_id }}">
                                </div>
                            </div>

                            {{-- Партия сырья — только читается, менять нельзя --}}
                            <div class="mb-3">
                                <label class="form-label">Партия сырья</label>
                                <input type="text" class="form-control bg-light"
                                       value="{{ $stoneReception->rawMaterialBatch->product->name ?? '—' }}{{ $stoneReception->rawMaterialBatch?->batch_number ? ' №'.$stoneReception->rawMaterialBatch->batch_number : '' }}"
                                       readonly>
                                {{-- Скрытый input передаёт batch_id на сервер; data-remaining нужен JS --}}
                                <input type="hidden"
                                       name="raw_material_batch_id"
                                       id="raw_material_batch_id"
                                       value="{{ $stoneReception->raw_material_batch_id }}"
                                       data-remaining="{{ (float)($stoneReception->rawMaterialBatch?->remaining_quantity ?? 0) }}">
                            </div>

                            {{-- Расход сырья с полем дельты --}}
                            <div class="card mb-4 border-secondary border-opacity-25">
                                <div class="card-header bg-light py-2 d-flex align-items-center gap-2">
                                    <span>🪵</span> <strong>Расход сырья</strong>
                                </div>
                                <div class="card-body py-3">
                                    <div class="row align-items-end g-3">

                                        {{-- Текущий --}}
                                        <div class="col-auto">
                                            <label class="form-label text-muted small mb-1">Сейчас</label>
                                            <div class="form-control bg-light text-muted" style="min-width:110px">
                                                {{ number_format($stoneReception->raw_quantity_used, 3, '.', '') }} м³
                                            </div>
                                        </div>

                                        {{-- Знак + --}}
                                        <div class="col-auto pb-1 fs-5 text-muted">+</div>

                                        {{-- Поле дельты --}}
                                        <div class="col-auto">
                                            <label class="form-label small mb-1">
                                                Изменение <span class="text-muted fw-normal">(м³, можно «−»)</span>
                                            </label>
                                            <input type="number"
                                                   name="raw_quantity_delta"
                                                   id="raw_quantity_delta"
                                                   class="form-control @error('raw_quantity_delta') is-invalid @enderror"
                                                   style="width:130px"
                                                   step="0.001"
                                                   placeholder="0.000"
                                                   value="{{ old('raw_quantity_delta', 0) }}">
                                            @error('raw_quantity_delta')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </div>

                                        {{-- Знак = --}}
                                        <div class="col-auto pb-1 fs-5 text-muted">=</div>

                                        {{-- Итог --}}
                                        <div class="col-auto">
                                            <label class="form-label text-muted small mb-1">Итого</label>
                                            <div id="raw_result" class="form-control bg-light fw-bold" style="min-width:110px">
                                                {{ number_format($stoneReception->raw_quantity_used, 3, '.', '') }} м³
                                            </div>
                                        </div>

                                        {{-- Доступно в партии --}}
                                        <div class="col">
                                            <small id="raw_remaining_info" class="text-info d-block mt-2"></small>
                                        </div>

                                    </div>
                                </div>
                            </div>

                            {{-- Склад --}}
                            <div class="mb-3">
                                <label class="form-label">Склад <span class="text-danger">*</span></label>
                                @if(env('DEFAULT_STORE_ID'))
                                    <input type="text" class="form-control" value="{{ $defaultStore->name ?? 'Склад по умолчанию' }}" readonly>
                                    <input type="hidden" name="store_id" value="{{ $defaultStore->id }}">
                                    <small class="text-muted">Приемка только на склад "{{ $defaultStore->name }}"</small>
                                @else
                                    <select name="store_id" class="form-select @error('store_id') is-invalid @enderror" required>
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

                            {{-- Продукты --}}
                            <div class="mb-4">
                                <label class="form-label fw-semibold">Продукция <span class="text-danger">*</span></label>

                                {{-- Шапка колонок --}}
                                <div class="row text-muted small fw-semibold px-1 mb-1" style="font-size:11px">
                                    <div class="col-5">Продукт</div>
                                    <div class="col-2 text-end">Сейчас</div>
                                    <div class="col-2 text-center">Изменение</div>
                                    <div class="col-2 text-end">Итого</div>
                                    <div class="col-1"></div>
                                </div>

                                {{-- Существующие позиции: рендерим на сервере, дельта вводится пользователем --}}
                                <div id="existing-products">
                                    @foreach($stoneReception->items as $item)
                                        @php $current = (float)$item->quantity; $idx = $loop->index; @endphp
                                        <div class="row align-items-center mb-2 px-1 existing-row"
                                             data-current="{{ $current }}">

                                            {{-- product_id передаём всегда --}}
                                            <input type="hidden" name="products[{{ $idx }}][product_id]" value="{{ $item->product_id }}">
                                            {{-- delta — то что ввёл пользователь, по умолчанию 0 --}}
                                            <input type="hidden" class="js-qty-out" name="products[{{ $idx }}][quantity]" value="{{ $current }}">

                                            <div class="col-5">
                                                <span class="small">{{ $item->product->name ?? '—' }}</span>
                                            </div>
                                            <div class="col-2 text-end text-muted small">
                                                {{ number_format($current, 3, '.', '') }}
                                            </div>
                                            <div class="col-2">
                                                <input type="number"
                                                       class="form-control form-control-sm js-delta"
                                                       step="0.001"
                                                       value="0"
                                                       placeholder="0">
                                            </div>
                                            <div class="col-2 text-end">
                                                <span class="js-result small fw-semibold">{{ number_format($current, 3, '.', '') }}</span>
                                            </div>
                                            <div class="col-1 text-end">
                                                <button type="button" class="btn btn-sm btn-outline-danger js-remove-existing" title="Убрать">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                {{-- Новые продукты: тот же формат — "0 + delta = итого" --}}
                                <div id="new-products-container" class="mt-1"></div>

                                <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="addProductBtn">
                                    <i class="bi bi-plus-circle"></i> Добавить новый продукт
                                </button>

                                <div class="mt-3 p-2 bg-light rounded">
                                    <strong>Всего продукции:</strong> <span id="totalProducts">0</span> м²
                                </div>
                            </div>

                            {{-- Примечания --}}
                            <div class="mb-3">
                                <label class="form-label">Примечания</label>
                                <textarea name="notes" class="form-control @error('notes') is-invalid @enderror" rows="3">{{ old('notes', $stoneReception->notes) }}</textarea>
                                @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            @if(auth()->user()?->isAdmin())
                                {{-- Поле для администратора: ручная дата --}}
                                <div class="mb-3 p-3 border border-warning rounded bg-warning bg-opacity-10">
                                    <label class="form-label fw-semibold text-warning-emphasis">
                                        <i class="bi bi-calendar-event"></i> Дата создания
                                        <span class="badge bg-warning text-dark ms-1" style="font-size:.7rem">Только для админа</span>
                                    </label>
                                    <input type="datetime-local"
                                           name="manual_created_at"
                                           class="form-control"
                                           value="{{ old('manual_created_at', $stoneReception->created_at->format('Y-m-d\TH:i')) }}">
                                    <div class="form-text">Измените если нужно скорректировать дату приёмки</div>
                                </div>
                            @endif

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i> Сохранить
                                </button>
                                <a href="{{ route('stone-receptions.index') }}" class="btn btn-outline-secondary">Отмена</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm mt-4">
                    <div class="card-header bg-white"><h5 class="mb-0">ℹ️ Информация</h5></div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-4">ID:</dt><dd class="col-sm-8">{{ $stoneReception->id }}</dd>
                            <dt class="col-sm-4">Создано:</dt><dd class="col-sm-8">{{ $stoneReception->created_at->format('d.m.Y H:i:s') }}</dd>
                            <dt class="col-sm-4">Обновлено:</dt><dd class="col-sm-8">{{ $stoneReception->updated_at->format('d.m.Y H:i:s') }}</dd>
                            <dt class="col-sm-4">Позиций:</dt><dd class="col-sm-8">{{ $stoneReception->items->count() }}</dd>
                        </dl>
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

            // ── Константы из PHP ──────────────────────────────────────────────────────
            const currentRaw = {{ (float)$stoneReception->raw_quantity_used }};

            // ── Элементы сырья ────────────────────────────────────────────────────────
            const rawDeltaInput  = document.getElementById('raw_quantity_delta');
            const rawResultDiv   = document.getElementById('raw_result');
            const rawRemInfo     = document.getElementById('raw_remaining_info');
            const batchSelect    = document.getElementById('raw_material_batch_id');

            function updateRaw() {
                const delta  = parseFloat(rawDeltaInput.value) || 0;
                const result = Math.round((currentRaw + delta) * 1000) / 1000;

                rawResultDiv.textContent = result.toFixed(3) + ' м³';
                rawResultDiv.classList.toggle('text-danger', result < 0);
                rawResultDiv.classList.toggle('fw-bold', true);

                // Партия теперь скрытый input — берём data-remaining напрямую
                const batchInput = document.getElementById('raw_material_batch_id');
                if (batchInput?.value) {
                    const remaining = parseFloat(batchInput.dataset.remaining) || 0;
                    // Доступно = остаток в партии + то что уже занято этой приёмкой
                    // (при сохранении старый расход вернётся и спишется новый)
                    const available = Math.round((remaining + currentRaw) * 1000) / 1000;
                    rawRemInfo.textContent = `Доступно для изменения: ${available.toFixed(3)} м³`;
                    rawDeltaInput.setCustomValidity(result > available ? 'Превышает доступный остаток' : '');
                }
            }

            rawDeltaInput.addEventListener('input', updateRaw);
            updateRaw();

            // ── Существующие продукты: дельта → итог → скрытый input ─────────────────
            document.querySelectorAll('.existing-row').forEach(row => {
                const current    = parseFloat(row.dataset.current);
                const deltaInput = row.querySelector('.js-delta');
                const resultSpan = row.querySelector('.js-result');
                const qtyOut     = row.querySelector('.js-qty-out');   // этот отправится на сервер
                const removeBtn  = row.querySelector('.js-remove-existing');

                function updateRow() {
                    const delta  = parseFloat(deltaInput.value) || 0;
                    const result = Math.round((current + delta) * 1000) / 1000;

                    resultSpan.textContent = result.toFixed(3);
                    resultSpan.classList.toggle('text-danger', result < 0);

                    // Пишем итоговое значение — сервер получит его как quantity
                    qtyOut.value = result;
                    updateTotal();
                }

                deltaInput.addEventListener('input', updateRow);

                // Удалить существующий продукт — ставим итог = 0
                removeBtn.addEventListener('click', function () {
                    deltaInput.value = (-current).toFixed(3);
                    qtyOut.value = 0;
                    resultSpan.textContent = '0.000';
                    resultSpan.classList.add('text-danger');
                    row.style.opacity = '0.45';
                    deltaInput.disabled = true;
                    this.disabled = true;
                    updateTotal();
                });
            });

            // ── Новые продукты ────────────────────────────────────────────────────────
            let newIdx = {{ $stoneReception->items->count() }};
            const newContainer = document.getElementById('new-products-container');

            function addNewProduct() {
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

                const deltaInput  = row.querySelector('.js-new-delta');
                const resultSpan  = row.querySelector('.js-new-result');
                const qtyOut      = row.querySelector('.js-new-qty-out');

                // При вводе дельты — итог = дельта (т.к. предыдущее = 0)
                deltaInput.addEventListener('input', function () {
                    const val = parseFloat(this.value) || 0;
                    resultSpan.textContent = val > 0 ? val.toFixed(3) : '—';
                    resultSpan.classList.toggle('text-muted', val <= 0);
                    qtyOut.value = val > 0 ? val : 0;
                    updateTotal();
                });

                // Кнопка удалить строку
                row.querySelector('.js-remove-new').addEventListener('click', function () {
                    row.remove();
                    updateTotal();
                });

                if (window.ProductPicker) window.ProductPicker.initRow(row);
            }

            document.getElementById('addProductBtn').addEventListener('click', addNewProduct);

            // ── Итого по всем продуктам ───────────────────────────────────────────────
            function updateTotal() {
                let total = 0;
                // Существующие
                document.querySelectorAll('#existing-products .js-qty-out').forEach(el => {
                    total += parseFloat(el.value) || 0;
                });
                // Новые
                newContainer.querySelectorAll('.js-new-qty-out').forEach(el => {
                    total += parseFloat(el.value) || 0;
                });
                document.getElementById('totalProducts').textContent = total.toFixed(2);
            }

            updateTotal();

            // ── Валидация перед отправкой ─────────────────────────────────────────────
            document.getElementById('receptionForm').addEventListener('submit', function (e) {
                const rawResult = currentRaw + (parseFloat(rawDeltaInput.value) || 0);
                if (rawResult < 0) {
                    e.preventDefault();
                    alert('Итоговый расход сырья не может быть отрицательным');
                    return;
                }

                // Проверяем новые продукты — нужен выбранный продукт и delta > 0
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
                if (!ok) { e.preventDefault(); alert('Для новых продуктов: выберите продукт и укажите количество больше 0'); }
            });
        });
    </script>
@endpush

{{-- Шаблон для новых продуктов: формат "0 (readonly) + delta = итого" --}}
<template id="editPickerRowTemplate">
    <div class="new-product-row row align-items-center mb-2 px-1">

        {{-- Поиск продукта --}}
        <div class="col-5 position-relative">
            <div class="input-group input-group-sm">
                <input type="text"
                       id="edit_search___IDX__"
                       data-tpl-idx="1"
                       class="form-control product-picker-search"
                       placeholder="Найти продукт..."
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

        {{-- Сейчас: всегда 0 для новых --}}
        <div class="col-2 text-end text-muted small">0.000</div>

        {{-- Delta: вводит пользователь --}}
        <div class="col-2">
            <input type="number"
                   class="form-control form-control-sm js-new-delta"
                   step="0.001" min="0.001"
                   placeholder="0"
                   value="">
        </div>

        {{-- Итог = delta (т.к. current = 0) — это и есть quantity --}}
        <div class="col-2 text-end">
            <span class="js-new-result small fw-semibold text-muted">—</span>
        </div>

        {{-- Скрытые поля для отправки --}}
        <input type="hidden"
               id="edit_pid___IDX__"
               name="products[__IDX__][product_id]"
               data-tpl-idx="1">
        <input type="hidden"
               name="products[__IDX__][quantity]"
               class="js-new-qty-out"
               value="0"
               data-tpl-idx="1">

        {{-- Удалить --}}
        <div class="col-1 text-end">
            <button type="button" class="btn btn-sm btn-outline-danger js-remove-new" title="Удалить">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="modal fade" id="edit_modal___IDX__" tabindex="-1" data-tpl-idx="1">
            <div class="modal-dialog modal-lg">
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
