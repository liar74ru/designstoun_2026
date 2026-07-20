@extends('layouts.app')

@section('title', 'Общий дашборд предприятия')

@section('content')
    <div class="container py-3 py-md-4">

        <x-page-header
            title="📊 Общий дашборд предприятия"
            mobile-title="📊 Общий дашборд"
        />

        @include('partials.alerts')

        {{-- Фильтры: период + отдел + камень/сырьё (универсальный partial) --}}
        @include('partials.filters', [
            'filterDepartments'  => $filterDepartments,
            'departmentDefaults' => [],
            'filterRawProducts'  => $filterRawProducts,
            'rawProductParam'    => 'product_id',
        ])

        {{-- Переключатель вкладок «Плитка / Сырьё» --}}
        <div class="btn-group w-100 mb-3" role="group" aria-label="Переключатель вкладок">
            <button type="button" class="btn btn-primary tab-btn" data-tab="tiles">
                <i class="bi bi-grid-3x3-gap me-1"></i> Плитка
            </button>
            <button type="button" class="btn btn-outline-secondary tab-btn" data-tab="raw">
                <i class="bi bi-box-seam me-1"></i> Сырьё
            </button>
        </div>

        {{-- ══════════ Вкладка «Плитка» ══════════ --}}
        <div id="tab-tiles">
        @if($departments->isEmpty())
            <div class="alert alert-info">За выбранный период производства не найдено.</div>
        @else

            {{-- Итоги по предприятию --}}
            <div class="row g-2 mb-3">
                <div class="col-12 col-md-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2 px-3">
                            <div class="text-muted small mb-1">Произведено, м²</div>
                            <div class="fs-4 fw-bold">{{ number_format($grandQuantity, 3, ',', ' ') }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2 px-3">
                            <div class="text-muted small mb-1">ФОТ пильщиков</div>
                            <div class="fs-4 fw-bold text-primary">{{ number_format($grandPay, 0, ',', ' ') }} ₽</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2 px-3">
                            <div class="text-muted small mb-1">ФОТ мастеров</div>
                            <div class="fs-4 fw-bold text-success">{{ number_format($grandMasterPay, 0, ',', ' ') }} ₽</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Разбивка по отделам --}}
            @foreach($departments as $dept)
                <div class="card shadow-sm mb-3">
                    <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap gap-1">
                        <span class="fw-semibold">
                            <i class="bi bi-building me-1"></i>{{ $dept['department']?->name ?? 'Без отдела' }}
                        </span>
                        <span class="small text-muted">
                            <span class="me-2">{{ number_format($dept['totalQuantity'], 3, ',', ' ') }} м²</span>
                            <span class="text-primary me-2">пильщики {{ number_format($dept['totalPay'], 0, ',', ' ') }} ₽</span>
                            <span class="text-success">мастера {{ number_format($dept['totalMasterPay'], 0, ',', ' ') }} ₽</span>
                        </span>
                    </div>
                    <div class="table-responsive">
                        <table class="table mb-0" style="font-size:.75rem;table-layout:auto">
                            <colgroup>
                                <col>
                                <col style="width:1%">
                                <col style="width:1%">
                                <col style="width:1%">
                            </colgroup>
                            <thead class="table-light">
                                <tr>
                                    <th style="border-left:4px solid transparent;padding:.3rem .1rem .3rem .4rem">Плитка</th>
                                    <th class="text-end text-nowrap" style="padding:.3rem .25rem">м²</th>
                                    <th class="text-end text-nowrap" style="padding:.3rem .25rem">Пильщики ₽</th>
                                    <th class="text-end text-nowrap" style="border-right:4px solid transparent;padding:.3rem .4rem .3rem .25rem">Мастера ₽</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach($dept['summary'] as $row)
                                @php
                                    $skuColor = \App\Models\Product::getColorBySku($row['product']?->sku);
                                    $skuBg    = $skuColor === '#FFFFFF' ? '' : 'background:' . $skuColor . '18;';
                                @endphp
                                <tr>
                                    <td style="border-left:4px solid {{ $skuColor }};{{ $skuBg }};word-break:break-word;padding:.3rem .1rem .3rem .4rem">
                                        {{ $row['product']?->name ?? '—' }}
                                        @if(!empty($row['is_undercut']))
                                            <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem">подкол 80%</span>
                                        @endif
                                        @if(!empty($row['is_edging']))
                                            <span class="badge bg-info text-dark ms-1" style="font-size:.6rem">торцовка</span>
                                        @endif
                                        @if(!empty($row['is_small_tile']))
                                            <span class="badge bg-info text-dark ms-1" style="font-size:.6rem">< 50мм</span>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap" style="{{ $skuBg }};padding:.3rem .25rem">
                                        {{ number_format($row['quantity'], 3, ',', ' ') }}
                                    </td>
                                    <td class="text-end text-nowrap text-primary" style="{{ $skuBg }};padding:.3rem .25rem">
                                        {{ number_format($row['pay'], 0, ',', ' ') }}
                                    </td>
                                    <td class="text-end text-nowrap fw-semibold text-success" style="border-right:4px solid {{ $skuColor }};{{ $skuBg }};padding:.3rem .4rem .3rem .25rem">
                                        {{ number_format($row['masterPay'], 0, ',', ' ') }}
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th class="fw-bold" style="padding:.3rem .1rem .3rem .4rem">ИТОГО:</th>
                                    <th class="text-end text-nowrap fw-semibold" style="padding:.3rem .25rem">
                                        {{ number_format($dept['totalQuantity'], 3, ',', ' ') }}
                                    </th>
                                    <th class="text-end text-nowrap text-primary" style="padding:.3rem .25rem">
                                        {{ number_format($dept['totalPay'], 0, ',', ' ') }}
                                    </th>
                                    <th class="text-end text-nowrap text-success" style="padding:.3rem .4rem .3rem .25rem">
                                        {{ number_format($dept['totalMasterPay'], 0, ',', ' ') }}
                                    </th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            @endforeach

        @endif
        </div>{{-- /#tab-tiles --}}

        {{-- ══════════ Вкладка «Сырьё» ══════════ --}}
        <div id="tab-raw" style="display:none">
            @if($incomingRaw->isEmpty())
                <div class="alert alert-info">За выбранный период поступлений сырья не найдено.</div>
            @else
                <div class="card shadow-sm mb-3">
                    <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap gap-1">
                        <span class="fw-semibold">
                            <i class="bi bi-box-seam me-1"></i>Входящее сырьё за период
                        </span>
                        <span class="small text-muted">
                            итого {{ number_format($incomingRawTotal, 3, ',', ' ') }} м³
                        </span>
                    </div>
                    <div class="table-responsive">
                        <table class="table mb-0" style="font-size:.75rem;table-layout:auto">
                            <colgroup>
                                <col>
                                <col style="width:1%">
                                <col style="width:1%">
                            </colgroup>
                            <thead class="table-light">
                                <tr>
                                    <th style="border-left:4px solid transparent;padding:.3rem .1rem .3rem .4rem">Камень</th>
                                    <th class="text-end text-nowrap" style="padding:.3rem .25rem">Ед. изм.</th>
                                    <th class="text-end text-nowrap" style="border-right:4px solid transparent;padding:.3rem .4rem .3rem .25rem">Поступило</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach($incomingRaw as $row)
                                @php
                                    $skuColor = \App\Models\Product::getColorBySku($row['product']?->sku);
                                    $skuBg    = $skuColor === '#FFFFFF' ? '' : 'background:' . $skuColor . '18;';
                                @endphp
                                <tr>
                                    <td style="border-left:4px solid {{ $skuColor }};{{ $skuBg }};word-break:break-word;padding:.3rem .1rem .3rem .4rem">
                                        {{ $row['product']?->name ?? '—' }}
                                    </td>
                                    <td class="text-end text-nowrap text-muted" style="{{ $skuBg }};padding:.3rem .25rem">
                                        {{ $row['uom'] }}
                                    </td>
                                    <td class="text-end text-nowrap fw-semibold" style="border-right:4px solid {{ $skuColor }};{{ $skuBg }};padding:.3rem .4rem .3rem .25rem">
                                        {{ number_format($row['quantity'], 3, ',', ' ') }}
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th class="fw-bold" style="padding:.3rem .1rem .3rem .4rem">ИТОГО:</th>
                                    <th class="text-end text-nowrap text-muted" style="padding:.3rem .25rem">м³</th>
                                    <th class="text-end text-nowrap fw-semibold" style="padding:.3rem .4rem .3rem .25rem">
                                        {{ number_format($incomingRawTotal, 3, ',', ' ') }}
                                    </th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            @endif
        </div>{{-- /#tab-raw --}}
    </div>
@endsection

@push('scripts')
    @vite(['resources/js/product-picker.js'])
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // ── Переключатель вкладок «Плитка / Сырьё» ────────────────────────────
            (function () {
                const STORAGE_KEY = 'enterprise_dashboard_active_tab';
                const tabButtons  = document.querySelectorAll('.tab-btn');
                const panels = {
                    tiles: document.getElementById('tab-tiles'),
                    raw:   document.getElementById('tab-raw'),
                };

                function applyTab(tab) {
                    if (!panels[tab]) tab = 'tiles';
                    Object.keys(panels).forEach(function (key) {
                        panels[key].style.display = key === tab ? '' : 'none';
                    });
                    tabButtons.forEach(function (btn) {
                        const active = btn.dataset.tab === tab;
                        btn.classList.toggle('btn-primary', active);
                        btn.classList.toggle('btn-outline-secondary', !active);
                    });
                }

                applyTab(localStorage.getItem(STORAGE_KEY) || 'tiles');
                tabButtons.forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        const tab = this.dataset.tab;
                        applyTab(tab);
                        localStorage.setItem(STORAGE_KEY, tab);
                    });
                });
            })();
        });
    </script>
@endpush
