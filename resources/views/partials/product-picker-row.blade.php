{{--
    Универсальная строка выбора продукта для повторяющихся списков (templates).
    Парные элементы — поиск + кнопка дерева (+ удалить) сверху, qty/подкол/коэф снизу.

    Параметры:
      $name            — string: корневое имя массива (default: 'products')
      $index           — string|int: индекс или плейсхолдер для <template>-клонирования (default: '__IDX__')
      $value           — string: product_id для предзаполнения (default: '')
      $label           — string: название продукта для предзаполнения (default: '')
      $quantity        — string: значение qty для предзаполнения (default: '')
      $placeholder     — string: placeholder поиска (default: 'Название продукта...')
      $unit            — string: 'м²' или 'м³' (default: 'м²')
      $dynamicUnit     — bool (default: false) — если true, ставит data-dynamic-unit на строку,
                         и JS (product-picker.js) подменяет единицу (.product-picker-unit) на uom выбранного товара
      $qtyStep         — string (default: '0.001')
      $qtyMin          — string (default: '0.001')
      $qtyWidth        — string (default: '130px')
      $qtyMode         — 'simple' | 'delta' | 'none' (default: 'simple')
                         simple — input qty (как в stone-receptions/create, supplier-orders, workshops)
                         delta  — UI «0 + delta = result» с .js-new-delta/.js-new-result/.js-new-qty-out (для stone-receptions/edit)
                         none   — qty не рендерится (потребитель сам добавит)
      $showUndercut    — bool (default: false) — чекбокс «80% подкол»
      $isUndercut      — bool (default: false) — предзаполнение чекбокса
      $undercutClass   — string (default: 'undercut-checkbox') — класс чекбокса (для совместимости с edit)
      $showEdging      — bool (default: false) — чекбокс «Торцовка» (скрыт по умолчанию, JS показывает по SKU партии 04-XX)
      $isEdging        — bool (default: false) — предзаполнение чекбокса
      $edgingClass     — string (default: 'edging-checkbox')
      $showCoeff       — bool (default: false) — отображение коэффициента
      $coeffClass      — string (default: 'coeff-display') — класс span с коэффициентом
      $showRemove      — bool (default: true)
      $requiredQty     — bool (default: true)
      $requiredProduct — bool (default: true)
      $skuPrefix       — string|null (default: null) — data-sku-prefix
      $extraRowClass   — string (default: '') — дополнительные классы на корневой .product-picker-row
--}}
@php
    $name            = $name            ?? 'products';
    $index           = $index           ?? '__IDX__';
    $value           = $value           ?? '';
    $label           = $label           ?? '';
    $quantity        = $quantity        ?? '';
    $placeholder     = $placeholder     ?? 'Название продукта...';
    $unit            = $unit            ?? 'м²';
    $qtyStep         = $qtyStep         ?? '0.001';
    $qtyMin          = $qtyMin          ?? '0.001';
    $qtyWidth        = $qtyWidth        ?? '130px';
    $qtyMode         = $qtyMode         ?? 'simple';
    $showUndercut    = $showUndercut    ?? false;
    $isUndercut      = $isUndercut      ?? false;
    $undercutClass   = $undercutClass   ?? 'undercut-checkbox';
    $showEdging      = $showEdging      ?? false;
    $isEdging        = $isEdging        ?? false;
    $edgingClass     = $edgingClass     ?? 'edging-checkbox';
    $showCoeff       = $showCoeff       ?? false;
    $coeffClass      = $coeffClass      ?? 'coeff-display';
    $showRemove      = $showRemove      ?? true;
    $requiredQty     = $requiredQty     ?? true;
    $requiredProduct = $requiredProduct ?? true;
    $skuPrefix       = $skuPrefix       ?? null;
    $extraRowClass   = $extraRowClass   ?? '';
    $dynamicUnit     = $dynamicUnit     ?? false;

    $idxStr  = (string) $index;
    $hasRow2 = $qtyMode !== 'none' || $showUndercut || $showEdging || $showCoeff;
@endphp

<div class="product-picker-row {{ $extraRowClass }}"
     data-tpl-index="{{ $idxStr }}"
     @if($skuPrefix) data-sku-prefix="{{ $skuPrefix }}" @endif
     @if($dynamicUnit) data-dynamic-unit="1" @endif
     style="padding:.35rem 0;border-bottom:1px solid #f0f0f0">

    {{-- Строка 1: поиск + tree-кнопка + удалить --}}
    <div class="d-flex gap-1 align-items-start mb-1">
        <div class="flex-grow-1 position-relative">
            <div class="input-group input-group-sm">
                <input type="text"
                       id="search_{{ $idxStr }}"
                       data-tpl-index="{{ $idxStr }}"
                       class="form-control product-picker-search"
                       placeholder="{{ $placeholder }}"
                       autocomplete="off"
                       data-hidden-id="pid_{{ $idxStr }}"
                       value="{{ $label }}"
                       @if($requiredProduct) required @endif>
                <button type="button"
                        class="btn btn-outline-secondary product-picker-tree-btn"
                        data-modal="modal_{{ $idxStr }}"
                        data-hidden-id="pid_{{ $idxStr }}"
                        data-search-id="search_{{ $idxStr }}"
                        data-tpl-index="{{ $idxStr }}"
                        title="Выбрать из каталога">
                    <i class="bi bi-diagram-3"></i>
                </button>
            </div>
            <div class="product-picker-dropdown list-group shadow-sm"
                 id="drop_{{ $idxStr }}"
                 style="display:none;position:absolute;z-index:1000;width:100%;max-height:280px;overflow-y:auto">
            </div>
        </div>
        @if($showRemove)
        <button type="button"
                class="btn btn-sm btn-outline-danger product-picker-remove flex-shrink-0"
                style="height:31px"
                title="Удалить">
            <i class="bi bi-x-lg"></i>
        </button>
        @endif
    </div>

    {{-- Строка 2: количество + подкол + коэф (если есть что показывать) --}}
    @if($hasRow2)
    <div class="d-flex gap-2 align-items-center flex-wrap">

        @if($qtyMode === 'simple')
            <div class="input-group input-group-sm" style="width:{{ $qtyWidth }};flex-shrink:0">
                <span class="input-group-text product-picker-unit" style="font-size:.75rem">{{ $unit }}</span>
                <input type="number"
                       id="qty_{{ $idxStr }}"
                       name="{{ $name }}[{{ $idxStr }}][quantity]"
                       class="form-control product-picker-qty"
                       placeholder="0.000"
                       step="{{ $qtyStep }}" min="{{ $qtyMin }}"
                       data-tpl-index="{{ $idxStr }}"
                       value="{{ $quantity }}"
                       @if($requiredQty) required @endif>
            </div>
        @elseif($qtyMode === 'delta')
            <div class="d-flex align-items-center gap-1 text-muted small">
                <span>0</span><span>+</span>
                <input type="number"
                       class="form-control form-control-sm js-new-delta"
                       style="width:90px"
                       step="{{ $qtyStep }}" min="{{ $qtyMin }}"
                       placeholder="0.000"
                       data-tpl-index="{{ $idxStr }}">
                <span>=</span>
                <span class="js-new-result fw-semibold text-muted" data-tpl-index="{{ $idxStr }}">—</span>
                <span class="product-picker-unit">{{ $unit }}</span>
            </div>
        @endif

        @if($showUndercut)
            <div class="form-check mb-0 flex-shrink-0">
                <input class="form-check-input {{ $undercutClass }}"
                       type="checkbox"
                       id="undercut_{{ $idxStr }}"
                       name="{{ $name }}[{{ $idxStr }}][is_undercut]"
                       value="1"
                       data-tpl-index="{{ $idxStr }}"
                       @if($isUndercut) checked @endif>
                <label class="form-check-label small text-warning-emphasis fw-semibold"
                       for="undercut_{{ $idxStr }}"
                       data-tpl-index="{{ $idxStr }}"
                       title="Снижает коэффициент на 1.5">
                    80% подкол
                </label>
            </div>
        @endif

        @if($showEdging)
            <div class="form-check mb-0 flex-shrink-0 edging-wrapper" style="display:none">
                <input class="form-check-input {{ $edgingClass }}"
                       type="checkbox"
                       id="edging_{{ $idxStr }}"
                       name="{{ $name }}[{{ $idxStr }}][is_edging]"
                       value="1"
                       data-tpl-index="{{ $idxStr }}"
                       @if($isEdging) checked @endif>
                <label class="form-check-label small text-info-emphasis fw-semibold"
                       for="edging_{{ $idxStr }}"
                       data-tpl-index="{{ $idxStr }}"
                       title="Полностью заменяет коэффициент продукта на значение настройки EDGING_COEFF">
                    Торцовка
                </label>
            </div>
        @endif

        @if($showCoeff)
            <span class="text-muted small text-nowrap">
                коэф: <span class="{{ $coeffClass }} fw-semibold text-dark"
                            data-base-coeff=""
                            data-tpl-index="{{ $idxStr }}">—</span>
            </span>
        @endif

    </div>
    @endif

    {{-- Скрытый product_id --}}
    <input type="hidden"
           id="pid_{{ $idxStr }}"
           name="{{ $name }}[{{ $idxStr }}][product_id]"
           value="{{ $value }}"
           data-tpl-index="{{ $idxStr }}"
           @if($requiredProduct) required @endif>

    {{-- Скрытый qty (только для delta-режима) --}}
    @if($qtyMode === 'delta')
        <input type="hidden"
               name="{{ $name }}[{{ $idxStr }}][quantity]"
               class="js-new-qty-out"
               value="{{ $quantity !== '' ? $quantity : 0 }}"
               data-tpl-index="{{ $idxStr }}">
    @endif

    {{-- Модальное окно дерева --}}
    <div class="modal fade" id="modal_{{ $idxStr }}" tabindex="-1" data-tpl-index="{{ $idxStr }}">
        <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Выбрать из каталога</h5>
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
