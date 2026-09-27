@extends('layouts.app')

@section('title', 'Заявка ' . $order->name)

@section('content')
@php
    $fmtQty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
    $fmt1   = fn ($v) => number_format((float) $v, 1, '.', '');

    // Числа позиций считает OrderPositionService — здесь только итоги.
    $storeNames = $stores->pluck('name', 'id')->all();
    $hasNumbers = $rows->contains(fn ($r) => $r['totalQty'] !== null);

    $sumOrdered = $rows->sum('ordered');
    $sumShipped = $rows->sum('shipped');
    $sumLeft    = $rows->sum('left');
    $sumShort   = $hasNumbers ? $rows->sum('short') : null;

    // Позиция приглушена, если скрыта для всех отделов, которыми управляет пользователь.
    $hideIds  = $hideDepartments->pluck('id')->all();
    $isHidden = fn ($row) => $hideIds !== [] && array_diff($hideIds, $row['hiddenFor']) === [];
@endphp

<div class="container py-3 py-md-4">

    <x-page-header
        title="📋 Заявка {{ $order->name }}"
        mobileTitle="Заявка {{ $order->name }}"
        :backUrl="$backUrl"
        backLabel="К списку" />

    @include('partials.alerts')

    @if($errors->any())
        <div class="alert alert-danger py-2">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <div class="row g-2">

        {{-- Сводка: справа на широком экране, сверху на узком --}}
        <div class="col-lg-4 order-lg-2">
            <div style="position: sticky; top: 1rem">

                @include('orders.partials.changes-block', ['order' => $order, 'fmtQty' => $fmtQty])

                @include('orders.partials.summary-block')

                @if(! empty($attributes))
                    <div class="info-block">
                        <div class="info-block-header small fw-semibold">Доп. реквизиты</div>
                        <div class="info-block-body">
                            @foreach($attributes as $name => $value)
                                <div class="d-flex gap-2 py-1 {{ ! $loop->last ? 'border-bottom' : '' }}">
                                    <div class="text-muted flex-shrink-0" style="font-size:.78rem; width:130px">{{ $name }}</div>
                                    <div class="flex-grow-1" style="min-width:0">{{ $value }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

            </div>
        </div>

        {{-- Позиции --}}
        <div class="col-lg-8 order-lg-1">
            <div class="info-block">
                <div class="info-block-header d-flex justify-content-between align-items-center small fw-semibold">
                    <span>Позиции</span>
                    <span class="badge bg-secondary">{{ $rows->count() }}</span>
                </div>
                <div class="info-block-body p-0">

                    @if($rows->isEmpty())
                        <div class="text-center py-4">
                            <i class="bi bi-box-seam display-4 text-muted"></i>
                            <p class="text-muted mt-3 small mb-0">Нет позиций</p>
                        </div>
                    @else

                        {{-- Десктоп --}}
                        <div class="d-none d-md-block table-responsive">
                            <table class="table table-hover table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:34%">Позиция</th>
                                        <th style="min-width:130px">Готовность</th>
                                        <th class="text-end">Заказ</th>
                                        <th class="text-end">Отгр.</th>
                                        <th class="text-end">Склад</th>
                                        <th class="text-end">Изготовлено</th>
                                        <th class="text-end">Всего</th>
                                    </tr>
                                </thead>
                                <tbody>
                                @foreach($rows as $row)
                                    @php
                                        $canMark = $row['product'] && ! $row['done'];
                                    @endphp
                                    <tr class="order-pos {{ $row['isReady'] ? 'is-ready' : '' }} {{ $isHidden($row) ? 'is-hidden' : '' }}"
                                        style="{{ $row['color'] === '#FFFFFF' ? '' : '--bs-table-bg:' . $row['color'] . '18;' }}"
                                        @if($canMark) data-position="{{ $order->id }}-{{ $row['product']->id }}" @endif
                                        @if($row['product']) data-hide-key="{{ $order->id }}-{{ $row['product']->id }}" @endif>
                                        <td>
                                            <div class="d-flex align-items-start gap-2">
                                                @if($canMark)
                                                    @include('orders.partials.ready-toggle', ['order' => $order, 'product' => $row['product']])
                                                @endif
                                                <span class="flex-shrink-0"
                                                      style="width:3px;align-self:stretch;border-radius:2px;background:{{ $row['color'] }}"></span>
                                                <ion-icon name="{{ $row['icon'] }}" class="text-muted flex-shrink-0 mt-1"></ion-icon>
                                                <div style="min-width:0">
                                                    <div class="fw-semibold {{ $row['done'] ? 'text-decoration-line-through text-muted' : '' }}"
                                                         style="font-size:.84rem; line-height:1.25; word-break:break-word">
                                                        @if($row['product'])
                                                            @can('see-products')
                                                                <a href="{{ route('products.show', $row['product']->moysklad_id) }}"
                                                                   class="text-reset">{{ $row['name'] }}</a>
                                                            @else
                                                                {{ $row['name'] }}
                                                            @endcan
                                                        @else
                                                            {{ $row['name'] }}
                                                        @endif
                                                    </div>
                                                    @if($row['product']?->sku)
                                                        <div class="text-muted" style="font-size:.7rem; font-variant-numeric: tabular-nums">
                                                            {{ $row['product']->sku }}
                                                        </div>
                                                    @endif
                                                    @include('orders.partials.hidden-badge', ['order' => $order, 'row' => $row, 'class' => 'mt-1'])
                                                </div>
                                                <div class="ms-auto">
                                                    @include('orders.partials.hide-toggle', ['order' => $order, 'row' => $row, 'hideDepartments' => $hideDepartments])
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            @include('orders.partials.readiness', ['row' => $row, 'fmt1' => $fmt1])
                                        </td>
                                        <td class="text-end" style="white-space:nowrap; font-variant-numeric: tabular-nums">
                                            {{ $fmtQty($row['ordered']) }}{{ $row['item']->uom_name ? ' ' . $row['item']->uom_name : '' }}
                                            @include('orders.partials.change-mark', [
                                                'change' => $order->position_changes[$row['item']->product_moysklad_id] ?? null,
                                                'class'  => 'd-table ms-auto mt-1',
                                            ])
                                        </td>
                                        <td class="text-end {{ $row['partial'] ? 'fw-semibold text-primary' : 'text-muted' }}"
                                            style="white-space:nowrap; font-variant-numeric: tabular-nums">
                                            {{ $row['shipped'] > 0 ? $fmt1($row['shipped']) : '—' }}
                                        </td>
                                        <td class="text-end" style="white-space:nowrap">
                                            @include('orders.partials.position-cell', [
                                                'row' => $row, 'fmt1' => $fmt1,
                                                'field' => 'warehouse', 'storeNames' => $storeNames,
                                            ])
                                        </td>
                                        <td class="text-end" style="white-space:nowrap">
                                            @include('orders.partials.position-cell', [
                                                'row' => $row, 'fmt1' => $fmt1,
                                                'field' => 'produced', 'storeNames' => $storeNames,
                                            ])
                                            @include('orders.partials.receptions-link', ['order' => $order, 'row' => $row, 'class' => 'ms-1'])
                                        </td>
                                        <td class="text-end fw-semibold {{ $row['done'] || $row['totalQty'] === null ? '' : ($row['totalQty'] >= $row['left'] ? 'text-success' : 'text-danger') }}"
                                            style="white-space:nowrap; font-variant-numeric: tabular-nums">
                                            <span class="total-value">{{ $row['totalQty'] !== null ? $fmt1($row['totalQty']) : '—' }}</span>
                                            <span class="badge bg-success ready-badge">✓ готово</span>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="2" class="small">Итого</th>
                                        <th class="text-end" style="font-variant-numeric: tabular-nums">{{ $fmt1($sumOrdered) }}</th>
                                        <th class="text-end" style="font-variant-numeric: tabular-nums">{{ $fmt1($sumShipped) }}</th>
                                        <th colspan="3" class="text-end small text-muted">осталось {{ $fmt1($sumLeft) }}</th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        {{-- Мобильный --}}
                        <div class="d-md-none" style="padding:.35rem .4rem">
                            @foreach($rows as $row)
                                @include('orders.partials.mobile-position', ['row' => $row])
                            @endforeach

                            <div class="d-flex justify-content-between align-items-center pt-1 mt-1"
                                 style="border-top:1px solid #dee2e6">
                                <span class="small fw-semibold">Итого</span>
                                <span class="small" style="font-variant-numeric: tabular-nums">
                                    {{ $fmt1($sumShipped) }} из {{ $fmt1($sumOrdered) }}
                                </span>
                            </div>
                        </div>

                    @endif
                </div>
            </div>
        </div>

    </div>
</div>

@if($stores->isNotEmpty())
    @include('orders.partials.position-modal', ['order' => $order, 'stores' => $stores])
@endif
@include('orders.partials.departments-modal', ['departments' => $departments])
@include('orders.partials.delivery-date-modal')
@endsection

@include('orders.partials.ready-toggle-assets')
@include('orders.partials.hide-toggle-assets')
@include('orders.partials.mobile-position-assets')
@include('orders.partials.summary-block-assets')

@if($stores->isNotEmpty())
@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const modal     = document.getElementById('positionModal');
            const resetForm = document.getElementById('positionResetForm');
            if (!modal || !resetForm) return;

            const productInput = document.getElementById('position_product_id');
            const nameEl       = document.getElementById('position_name');
            const factInput    = document.getElementById('position_fact');
            const producedInput = document.getElementById('position_produced');
            const factHint     = document.getElementById('position_fact_hint');
            const producedHint = document.getElementById('position_produced_hint');
            const noteInput    = document.getElementById('position_note');
            const resetBtn     = document.getElementById('position_reset_btn');
            const allStoresBox = document.getElementById('position_all_stores');
            const sharedBox    = document.getElementById('position_shared');
            const sharedList   = document.getElementById('position_shared_list');
            const checkboxes   = Array.from(modal.querySelectorAll('.position-store'));

            // Шаблон маршрута сброса: последний сегмент — id товара, подставляем по клику.
            const resetBase = resetBtn.parentElement.dataset.resetUrl.replace(/\/0$/, '');

            let storeQty = {};
            let producedByStore = {};
            let visibleStores = [];

            const fmt = (v) => (Math.round(v * 1000) / 1000).toFixed(1);
            const checked = () => checkboxes.filter(c => c.checked).map(c => c.value);
            const sumOver = (map, ids) => ids.reduce((acc, id) => acc + (parseFloat(map[id]) || 0), 0);

            function renderQty() {
                modal.querySelectorAll('.position-store-qty').forEach(function (el) {
                    const qty = parseFloat(storeQty[el.dataset.storeId]) || 0;
                    el.textContent = fmt(qty);
                    el.classList.toggle('text-muted', qty === 0);
                });
            }

            // Пустые склады прячем: их в базе десяток, и список из нулей мешает искать.
            function applyStoreFilter() {
                const showAll = allStoresBox.checked;
                let lastVisible = null;

                checkboxes.forEach(function (box) {
                    const row = box.closest('.position-store-row');
                    const show = showAll || visibleStores.includes(box.value);

                    // Класс, а не style.display: у строки есть .d-flex с !important,
                    // инлайновый стиль его не перебьёт. .d-none в CSS идёт позже .d-flex.
                    row.classList.toggle('d-none', ! show);
                    row.style.borderBottom = '1px solid #f1f3f5';

                    if (show) lastVisible = row;
                });

                // Иначе разделитель последней строки сложится с рамкой контейнера
                if (lastVisible) lastVisible.style.borderBottom = 'none';
            }

            function renderHints() {
                const ids = checked();
                factHint.textContent = 'по складам: ' + fmt(sumOver(storeQty, ids));
                producedHint.textContent = 'по документам: ' + fmt(sumOver(producedByStore, ids));
            }

            modal.addEventListener('show.bs.modal', function (event) {
                const btn = event.relatedTarget;
                if (!btn) return;

                storeQty        = JSON.parse(btn.dataset.storeQty || '{}');
                producedByStore = JSON.parse(btn.dataset.producedStore || '{}');
                visibleStores   = JSON.parse(btn.dataset.visibleStores || '[]');
                const selected  = JSON.parse(btn.dataset.selected || '[]');

                productInput.value   = btn.dataset.productId;
                nameEl.textContent   = btn.dataset.name;
                factInput.value      = btn.dataset.warehouse;
                producedInput.value  = btn.dataset.produced;
                noteInput.value      = btn.dataset.note || '';

                sharedList.textContent  = btn.dataset.sharedWith || '';
                sharedBox.style.display = btn.dataset.sharedWith ? '' : 'none';

                checkboxes.forEach(c => { c.checked = selected.includes(c.value); });

                // Инлайн-style, а не [hidden]: .btn задаёт display и перебил бы атрибут.
                resetBtn.style.display = btn.dataset.hasSetting === '1' ? '' : 'none';
                resetForm.action       = resetBase + '/' + btn.dataset.productId;

                modal.dataset.focus = btn.dataset.focus || 'warehouse';

                allStoresBox.checked = false;

                renderQty();
                applyStoreFilter();
                renderHints();
            });

            allStoresBox.addEventListener('change', applyStoreFilter);

            modal.addEventListener('shown.bs.modal', function () {
                const input = modal.dataset.focus === 'produced' ? producedInput : factInput;
                input.focus();
                input.select();
            });

            // Смена набора складов пересчитывает оба поля: поправка мастера
            // относилась к прежнему набору и здесь уже не применима.
            checkboxes.forEach(function (box) {
                box.addEventListener('change', function () {
                    const ids = checked();
                    factInput.value     = fmt(sumOver(storeQty, ids));
                    producedInput.value = fmt(sumOver(producedByStore, ids));
                    renderHints();
                });
            });

            resetBtn.addEventListener('click', function () {
                if (confirm('Снять уточнения и показывать расчётные значения?')) {
                    resetForm.submit();
                }
            });
        });
    </script>
@endpush
@endif
