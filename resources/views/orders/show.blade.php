@extends('layouts.app')

@section('title', 'Заявка ' . $order->name)

@section('content')
@php
    $fmtQty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
    $fmt1   = fn ($v) => number_format((float) $v, 1, '.', '');

    // Позиции считаем один раз: и таблица, и карточки, и сводка берут готовые числа.
    $rows = $order->items->map(function ($item) use ($order, $productionStoreId) {
        $product = $item->product;

        $ordered = (float) $item->quantity;
        $shipped = (float) $item->shipped;
        $left    = max(0, $ordered - $shipped);

        // Уточнение мастера хранится поправкой к остатку МойСклад — движения по товару
        // переносятся на неё сами.
        $correction = $productionStoreId && $product
            ? $order->stockCorrections
                ->where('product_id', $product->id)
                ->firstWhere('store_id', $productionStoreId)
            : null;
        $delta = $correction ? (float) $correction->delta : 0.0;

        $baseQty = null;
        $prodQty = null;
        if ($productionStoreId && $product) {
            $stock   = $product->stocks->firstWhere('store_id', $productionStoreId);
            $baseQty = $stock ? (float) $stock->quantity : 0.0;
            $prodQty = max(0, $baseQty + $delta);
        }

        $totalQty = $product
            ? max(0, (float) $product->stocks->filter(fn ($s) => $s->store && ! $s->store->archived)->sum('quantity') + $delta)
            : null;

        return [
            'item'       => $item,
            'product'    => $product,
            'name'       => $product?->name ?? $item->product_name ?? '—',
            'ordered'    => $ordered,
            'shipped'    => $shipped,
            'left'       => $left,
            'done'       => $ordered > 0 && $shipped >= $ordered,
            'partial'    => $shipped > 0 && $shipped < $ordered,
            'baseQty'    => $baseQty,
            'prodQty'    => $prodQty,
            'totalQty'   => $totalQty,
            'correction' => $correction,
            'short'      => $prodQty === null ? null : max(0, $left - $prodQty),
            'ready'      => $left > 0 ? ($prodQty === null ? null : min(1, $prodQty / $left)) : 1.0,
            'color'      => \App\Models\Product::getColorBySku($product?->sku),
            'icon'       => \App\Models\Product::getIconBySku($product?->sku),
        ];
    });

    $sumOrdered = $rows->sum('ordered');
    $sumShipped = $rows->sum('shipped');
    $sumLeft    = $rows->sum('left');
    $sumShort   = $productionStoreId ? $rows->sum('short') : null;
    $percent    = $sumOrdered > 0 ? (int) round($sumShipped / $sumOrdered * 100) : 0;

    $stateColor = \App\Models\Order::stateColor($order->state_name);
@endphp

<div class="container py-3 py-md-4">

    <x-page-header
        title="📋 Заявка {{ $order->name }}"
        mobileTitle="Заявка {{ $order->name }}"
        :backUrl="$backUrl"
        backLabel="К списку">
        <x-slot name="actions">
            <span class="badge text-white fs-6" style="background-color: {{ $stateColor }}">
                {{ $order->state_name ?? '—' }}
            </span>
        </x-slot>
        <x-slot name="mobileActions">
            <span class="badge text-white" style="background-color: {{ $stateColor }}">
                {{ $order->state_name ?? '—' }}
            </span>
        </x-slot>
    </x-page-header>

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

                <div class="info-block">
                    <div class="info-block-header small fw-semibold">Заявка</div>
                    <div class="info-block-body">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="rounded-circle d-inline-block flex-shrink-0"
                                  style="width:10px;height:10px;background:{{ $stateColor }}"></span>
                            <span class="fs-5 fw-semibold">{{ $order->state_name ?? '—' }}</span>
                        </div>

                        <div class="mb-1">
                            <div class="text-muted" style="font-size:.78rem">Контрагент</div>
                            <div class="fw-semibold">{{ $order->counterparty?->name ?? $order->agent_name ?? '—' }}</div>
                        </div>

                        <div class="mb-1">
                            <div class="text-muted" style="font-size:.78rem">Дата</div>
                            <div style="font-variant-numeric: tabular-nums">
                                {{ $order->moment ? $order->moment->format('d.m.Y') : '—' }}
                            </div>
                        </div>

                        <div class="mb-2">
                            <div class="text-muted" style="font-size:.78rem">Отделы</div>
                            <div class="d-flex flex-wrap gap-1 mt-1">
                                @forelse($order->departments as $dept)
                                    <span class="badge bg-light text-dark border">{{ $dept->name }}</span>
                                @empty
                                    <span class="text-muted small">—</span>
                                @endforelse
                            </div>
                        </div>

                        @if($productionStore)
                            <div class="mb-2">
                                <div class="text-muted" style="font-size:.78rem">Склад комплектации</div>
                                <div>{{ $productionStore->name }}</div>
                            </div>
                        @endif

                        <div class="pt-2" style="border-top:1px solid #f1f3f5">
                            <div class="d-flex justify-content-between small">
                                <span class="text-muted">Отгружено</span>
                                <span style="font-variant-numeric: tabular-nums">{{ $percent }}%</span>
                            </div>
                            <div class="progress mt-1 mb-2" style="height:6px">
                                <div class="progress-bar bg-primary" style="width: {{ $percent }}%"></div>
                            </div>

                            @include('orders.partials.summary-row', [
                                'label' => 'Заказано',
                                'value' => $fmt1($sumOrdered),
                                'class' => '',
                                'last'  => false,
                            ])
                            @include('orders.partials.summary-row', [
                                'label' => 'Осталось отгрузить',
                                'value' => $fmt1($sumLeft),
                                'class' => '',
                                'last'  => ! $productionStoreId,
                            ])
                            @if($productionStoreId)
                                @include('orders.partials.summary-row', [
                                    'label' => 'Дефицит на производстве',
                                    'value' => $fmt1($sumShort),
                                    'class' => $sumShort > 0 ? 'text-danger fw-semibold' : 'text-success',
                                    'last'  => true,
                                ])
                            @endif
                        </div>
                    </div>
                </div>

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
                                        <th style="width:40%">Позиция</th>
                                        <th style="min-width:140px">Готовность</th>
                                        <th class="text-end">Заказ</th>
                                        <th class="text-end">Отгр.</th>
                                        <th class="text-end">
                                            Склад
                                            @if($productionStore)
                                                <div class="fw-normal text-muted text-truncate"
                                                     style="font-size:.68rem; max-width:160px"
                                                     title="{{ $productionStore->name }}">
                                                    {{ $productionStore->name }}
                                                </div>
                                            @endif
                                        </th>
                                        <th class="text-end">Всего</th>
                                    </tr>
                                </thead>
                                <tbody>
                                @foreach($rows as $row)
                                    <tr style="{{ $row['color'] === '#FFFFFF' ? '' : '--bs-table-bg:' . $row['color'] . '18;' }}">
                                        <td>
                                            <div class="d-flex align-items-start gap-2">
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
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            @include('orders.partials.readiness', ['row' => $row, 'fmt1' => $fmt1])
                                        </td>
                                        <td class="text-end" style="white-space:nowrap; font-variant-numeric: tabular-nums">
                                            {{ $fmtQty($row['ordered']) }}{{ $row['item']->uom_name ? ' ' . $row['item']->uom_name : '' }}
                                        </td>
                                        <td class="text-end {{ $row['partial'] ? 'fw-semibold text-primary' : 'text-muted' }}"
                                            style="white-space:nowrap; font-variant-numeric: tabular-nums">
                                            {{ $row['shipped'] > 0 ? $fmt1($row['shipped']) : '—' }}
                                        </td>
                                        <td class="text-end" style="white-space:nowrap">
                                            @include('orders.partials.stock-cell', [
                                                'row'               => $row,
                                                'fmt1'              => $fmt1,
                                                'productionStoreId' => $productionStoreId,
                                            ])
                                        </td>
                                        <td class="text-end fw-semibold {{ $row['done'] || $row['totalQty'] === null ? '' : ($row['totalQty'] >= $row['left'] ? 'text-success' : 'text-danger') }}"
                                            style="white-space:nowrap; font-variant-numeric: tabular-nums">
                                            {{ $row['totalQty'] !== null ? $fmt1($row['totalQty']) : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="2" class="small">Итого</th>
                                        <th class="text-end" style="font-variant-numeric: tabular-nums">{{ $fmt1($sumOrdered) }}</th>
                                        <th class="text-end" style="font-variant-numeric: tabular-nums">{{ $fmt1($sumShipped) }}</th>
                                        <th colspan="2" class="text-end small text-muted">осталось {{ $fmt1($sumLeft) }}</th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        {{-- Мобильный --}}
                        <div class="d-md-none" style="padding:.35rem .4rem">
                            @foreach($rows as $row)
                                <div style="border-left:3px solid {{ $row['color'] }};{{ $row['color'] === '#FFFFFF' ? '' : 'background:' . $row['color'] . '18;' }}padding:.35rem .45rem;border-radius:.25rem;margin-bottom:.3rem">
                                    <div class="d-flex align-items-start gap-1">
                                        <ion-icon name="{{ $row['icon'] }}" class="text-muted flex-shrink-0 mt-1"></ion-icon>
                                        <div class="flex-grow-1" style="min-width:0">
                                            <div class="fw-semibold {{ $row['done'] ? 'text-decoration-line-through text-muted' : '' }}"
                                                 style="font-size:.82rem; line-height:1.25; word-break:break-word">
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
                                        </div>
                                        <span style="white-space:nowrap; font-size:.78rem; font-variant-numeric: tabular-nums">
                                            {{ $fmtQty($row['ordered']) }}{{ $row['item']->uom_name ? ' ' . $row['item']->uom_name : '' }}
                                        </span>
                                    </div>

                                    @if($row['done'])
                                        <div class="text-muted mt-1" style="font-size:.74rem; font-variant-numeric: tabular-nums">
                                            отгружено {{ $fmt1($row['shipped']) }} — полностью
                                        </div>
                                    @else
                                        <div class="mt-1">
                                            @include('orders.partials.readiness', ['row' => $row, 'fmt1' => $fmt1])
                                        </div>
                                        <div class="d-flex justify-content-between mt-1"
                                             style="font-size:.74rem; font-variant-numeric: tabular-nums">
                                            <span>
                                                <span class="text-muted">осталось</span>
                                                <b>{{ $fmt1($row['left']) }}</b>
                                            </span>
                                            <span>
                                                <span class="text-muted">склад</span>
                                                @include('orders.partials.stock-cell', [
                                                    'row'               => $row,
                                                    'fmt1'              => $fmt1,
                                                    'productionStoreId' => $productionStoreId,
                                                ])
                                            </span>
                                            <span>
                                                <span class="text-muted">всего</span>
                                                <b class="{{ $row['totalQty'] === null ? '' : ($row['totalQty'] >= $row['left'] ? 'text-success' : 'text-danger') }}">
                                                    {{ $row['totalQty'] !== null ? $fmt1($row['totalQty']) : '—' }}
                                                </b>
                                            </span>
                                        </div>
                                    @endif
                                </div>
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

@if($productionStoreId)
    @include('orders.partials.correction-modal', ['order' => $order, 'productionStoreId' => $productionStoreId])
@endif
@endsection

@if($productionStoreId)
@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const modal     = document.getElementById('stockCorrectionModal');
            const resetForm = document.getElementById('correctionResetForm');
            if (!modal || !resetForm) return;

            const productInput = document.getElementById('correction_product_id');
            const nameEl       = document.getElementById('correction_name');
            const baseEl       = document.getElementById('correction_base');
            const factInput    = document.getElementById('correction_fact');
            const noteInput    = document.getElementById('correction_note');
            const hintEl       = document.getElementById('correction_hint');
            const resetBtn     = document.getElementById('correction_reset_btn');

            // Шаблон маршрута сброса: последний сегмент — id товара, подставляем по клику.
            const resetBase = resetBtn.parentElement.dataset.resetUrl.replace(/\/0$/, '');

            function updateHint() {
                const base = parseFloat(baseEl.dataset.value);
                const fact = parseFloat(factInput.value);
                if (isNaN(base) || isNaN(fact)) {
                    hintEl.textContent = '';
                    return;
                }
                const delta = Math.round((fact - base) * 1000) / 1000;
                hintEl.textContent = delta === 0
                    ? 'Совпадает с МойСклад — уточнение будет снято'
                    : 'Поправка ' + (delta > 0 ? '+' : '−') + Math.abs(delta).toFixed(1);
            }

            modal.addEventListener('show.bs.modal', function (event) {
                const btn = event.relatedTarget;
                if (!btn) return;

                productInput.value  = btn.dataset.productId;
                nameEl.textContent  = btn.dataset.name;
                baseEl.textContent  = btn.dataset.base;
                baseEl.dataset.value = btn.dataset.base;
                factInput.value     = btn.dataset.fact;
                noteInput.value     = btn.dataset.note || '';

                // Инлайн-style, а не [hidden]: .btn задаёт display и перебил бы атрибут.
                resetBtn.style.display = btn.dataset.hasCorrection === '1' ? '' : 'none';
                resetForm.action       = resetBase + '/' + btn.dataset.productId;

                updateHint();
            });

            modal.addEventListener('shown.bs.modal', function () {
                factInput.focus();
                factInput.select();
            });

            factInput.addEventListener('input', updateHint);

            resetBtn.addEventListener('click', function () {
                if (confirm('Снять уточнение и показывать остаток МойСклад?')) {
                    resetForm.submit();
                }
            });
        });
    </script>
@endpush
@endif
