@extends('layouts.app')

@section('title', 'Общий дашборд предприятия')

@section('content')
    <div class="container py-3 py-md-4">

        <x-page-header
            title="📊 Общий дашборд предприятия"
            mobile-title="📊 Общий дашборд"
        />

        @include('partials.alerts')

        {{-- Форма выбора периода --}}
        @php
            $weekButtons = [];
            for ($i = 0; $i <= 2; $i++) {
                $fri = \Carbon\Carbon::today()->startOfDay();
                while ($fri->dayOfWeek !== \Carbon\Carbon::FRIDAY) { $fri->subDay(); }
                $fri->subDays($i * 7);
                $thu = $fri->copy()->addDays(6)->endOfDay();
                $weekButtons[$i] = ['from' => $fri->format('Y-m-d'), 'to' => $thu->format('Y-m-d')];
            }
            $currentFrom = $dateFrom->format('Y-m-d');
            $currentTo   = $dateTo->format('Y-m-d');
        @endphp

        <form method="GET" id="period-form" class="card shadow-sm mb-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-2"
                 style="cursor:pointer" id="period-toggle" role="button">
                <span class="fw-semibold text-muted small">
                    <i class="bi bi-calendar3 me-1"></i> Период:
                    <strong class="text-body ms-1">
                        {{ $dateFrom->translatedFormat('d M Y') }} — {{ $dateTo->translatedFormat('d M Y') }}
                    </strong>
                </span>
                <i class="bi bi-chevron-down" id="period-chevron"></i>
            </div>
            <div id="period-collapse" style="display:none">
                <div class="card-body py-2 px-3">
                    <div class="d-flex flex-wrap gap-1 mb-2">
                        @foreach([0 => 'Тек. неделя', 1 => 'Пред. неделя', 2 => '2 нед. назад'] as $week => $label)
                            @php $isActive = $weekButtons[$week]['from'] === $currentFrom && $weekButtons[$week]['to'] === $currentTo; @endphp
                            <button type="button"
                                    class="btn btn-sm week-btn {{ $isActive ? 'btn-primary' : 'btn-outline-secondary' }}"
                                    data-week="{{ $week }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                    <div class="d-flex flex-wrap gap-2 align-items-end">
                        <div>
                            <label class="form-label small text-muted mb-1">С</label>
                            <input type="date" name="date_from" id="date_from"
                                   class="form-control form-control-sm" value="{{ $dateFrom->format('Y-m-d') }}">
                        </div>
                        <div>
                            <label class="form-label small text-muted mb-1">По</label>
                            <input type="date" name="date_to" id="date_to"
                                   class="form-control form-control-sm" value="{{ $dateTo->format('Y-m-d') }}">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Показать</button>
                    </div>
                </div>
            </div>
        </form>

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
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // ── Сворачиваемый блок периода ────────────────────────────────────────
            (function () {
                const STORAGE_KEY = 'enterprise_dashboard_period_open';
                const toggle   = document.getElementById('period-toggle');
                const collapse = document.getElementById('period-collapse');
                const chevron  = document.getElementById('period-chevron');

                function applyState(open) {
                    collapse.style.display = open ? '' : 'none';
                    chevron.className = open ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
                }

                applyState(localStorage.getItem(STORAGE_KEY) === 'open');
                toggle.addEventListener('click', function () {
                    const isHidden = collapse.style.display === 'none';
                    applyState(isHidden);
                    localStorage.setItem(STORAGE_KEY, isHidden ? 'open' : 'closed');
                });
            })();

            // ── Быстрые кнопки недель ─────────────────────────────────────────────
            function getWorkWeek(weeksAgo) {
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                const friday = new Date(today);
                while (friday.getDay() !== 5) { friday.setDate(friday.getDate() - 1); }
                friday.setDate(friday.getDate() - weeksAgo * 7);
                const thursday = new Date(friday);
                thursday.setDate(thursday.getDate() + 6);
                return { from: friday, to: thursday };
            }

            function formatDate(date) {
                const y = date.getFullYear();
                const m = String(date.getMonth() + 1).padStart(2, '0');
                const d = String(date.getDate()).padStart(2, '0');
                return `${y}-${m}-${d}`;
            }

            const dateFromInput = document.getElementById('date_from');
            const dateToInput   = document.getElementById('date_to');
            const form          = document.getElementById('period-form');

            document.querySelectorAll('.week-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const weeksAgo = parseInt(this.dataset.week, 10);
                    const { from, to } = getWorkWeek(weeksAgo);
                    dateFromInput.value = formatDate(from);
                    dateToInput.value   = formatDate(to);
                    form.submit();
                });
            });
        });
    </script>
@endpush
