@extends('layouts.app')

@section('title', ($isMaster ? 'Дашборд мастера' : 'Моя выработка') . ' — ' . $worker->name)

@section('content')
    <div class="container py-3 py-md-4">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="fs-2 mb-0 fw-bold">{{ $worker->name }}</h1>
            @if($worker->user)
                <a href="{{ route('workers.edit-user', $worker) }}"
                   class="btn btn-sm btn-outline-secondary"
                   title="Учётная запись / смена пароля">
                    <i class="bi bi-key"></i>
                </a>
            @endif
        </div>

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

        @if($summary->isEmpty())
            <div class="alert alert-info">
                За выбранный период приёмок не найдено.
            </div>
        @else

            @if($isMaster)
                {{-- Ставки мастера --}}
                <div class="row g-2 mb-3">
                    @foreach([
                        ['label' => 'Базовая ставка',  'value' => $rates['base']],
                        ['label' => 'Подкол > 80%',    'value' => $rates['undercut']],
                        ['label' => 'Фасовка в ящик',  'value' => $rates['packaging']],
                        ['label' => 'Плитка < 50мм',   'value' => $rates['smallTile']],
                    ] as $rate)
                    <div class="col-6 col-md-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body py-2 px-3">
                                <div class="text-muted small mb-1">{{ $rate['label'] }}</div>
                                <div class="fs-5 fw-bold">
                                    {{ number_format($rate['value'], 0, ',', ' ') }} <span class="text-muted small fw-normal">₽/м²</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body py-2 px-3">
                        <div class="text-muted small mb-1">Итого к выплате</div>
                        <div class="fs-4 fw-bold text-success">
                            {{ number_format($totalMasterPay, 0, ',', ' ') }} ₽
                        </div>
                    </div>
                </div>
            @else
                @if(auth()->user()->isAdmin() || auth()->id() === $worker->user?->id)
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body py-2 px-3">
                                <div class="text-muted small mb-1">Итого к выплате</div>
                                <div class="fs-4 fw-bold text-success">
                                    {{ number_format($totalPay, 0, ',', ' ') }} ₽
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body py-2 px-3">
                                <div class="text-muted small mb-1">Базовая ставка</div>
                                <div class="fs-4 fw-bold">{{ number_format(\App\Models\Product::pieceRate(), 0, ',', ' ') }} ₽</div>
                            </div>
                        </div>
                    </div>
                </div>
                @endif
            @endif

            {{-- Сводка по продуктам --}}
            @if($isMaster || auth()->user()->isAdmin() || auth()->id() === $worker->user?->id)
            <div class="card shadow-sm mb-3">
                <div class="card-header fw-semibold py-2 d-flex justify-content-between align-items-center">
                    <span>Сводка по продуктам за период</span>
                </div>
                <div class="table-responsive">
                    @if($isMaster)
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
                                    <th class="text-end text-nowrap" style="padding:.3rem .25rem .3rem .1rem">м²</th>
                                    <th class="text-end text-nowrap" style="padding:.3rem .25rem">Ставка</th>
                                    <th class="text-end text-nowrap" style="border-right:4px solid transparent;padding:.3rem .4rem .3rem .25rem">Сумма</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach($summary as $row)
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
                                    <td class="text-end text-nowrap" style="{{ $skuBg }};padding:.3rem .25rem .3rem .1rem">
                                        {{ number_format($row['quantity'], 3, ',', ' ') }}
                                    </td>
                                    <td class="text-end text-nowrap text-muted" style="{{ $skuBg }};padding:.3rem .25rem">
                                        {{ number_format($row['masterCost'], 0, ',', ' ') }} ₽
                                    </td>
                                    <td class="text-end text-nowrap fw-semibold text-success" style="border-right:4px solid {{ $skuColor }};{{ $skuBg }};padding:.3rem .4rem .3rem .25rem">
                                        {{ number_format($row['masterPay'], 0, ',', ' ') }} ₽
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th class="fw-bold" style="padding:.3rem .1rem .3rem .4rem">ИТОГО:</th>
                                    <th class="text-end text-nowrap fw-semibold" style="font-size:.9rem;padding:.3rem .25rem">
                                        {{ number_format($summary->sum('quantity'), 3, ',', ' ') }}
                                    </th>
                                    <th></th>
                                    <th class="text-end text-nowrap text-success" style="font-size:.9rem;padding:.3rem .4rem .3rem .25rem">
                                        {{ number_format($totalMasterPay, 0, ',', ' ') }} ₽
                                    </th>
                                </tr>
                            </tfoot>
                        </table>
                    @else
                        <table class="table mb-0" style="font-size:.75rem;table-layout:auto">
                            <colgroup>
                                <col>
                                <col style="width:1%">
                                <col style="width:1%">
                                <col style="width:1%">
                                <col style="width:1%">
                            </colgroup>
                            <thead class="table-light">
                            <tr>
                                <th style="border-left:4px solid transparent;padding:.3rem .1rem .3rem .4rem">Плитка</th>
                                <th class="text-end text-nowrap" style="padding:.3rem .25rem .3rem .1rem">м²</th>
                                <th class="text-end text-nowrap" style="padding:.3rem .25rem" title="Коэффициент зафиксирован на момент приёмки">Коэф.</th>
                                <th class="text-end text-nowrap" style="padding:.3rem .25rem">Ставка</th>
                                <th class="text-end text-nowrap" style="border-right:4px solid transparent;padding:.3rem .4rem .3rem .25rem">Сумма</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($summary as $row)
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
                                    </td>
                                    <td class="text-end text-nowrap" style="{{ $skuBg }};padding:.3rem .25rem .3rem .1rem">
                                        {{ number_format($row['quantity'], 3, ',', ' ') }}
                                    </td>
                                    <td class="text-end text-nowrap text-muted" style="{{ $skuBg }};padding:.3rem .25rem">
                                        ×{{ number_format($row['coeff'], 1, ',', ' ') }}
                                    </td>
                                    <td class="text-end text-nowrap text-muted" style="{{ $skuBg }};padding:.3rem .25rem">
                                        {{ number_format($row['prodCost'], 0, ',', ' ') }} ₽
                                    </td>
                                    <td class="text-end text-nowrap fw-semibold text-success" style="border-right:4px solid {{ $skuColor }};{{ $skuBg }};padding:.3rem .4rem .3rem .25rem">
                                        {{ number_format($row['pay'], 0, ',', ' ') }} ₽
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                            <tfoot class="table-light">
                            <tr>
                                <th class="fw-bold" style="padding:.3rem .1rem .3rem .4rem">ИТОГО:</th>
                                <th class="text-end text-nowrap fw-semibold" style="font-size:.9rem;padding:.3rem .25rem">
                                    {{ number_format($summary->sum('quantity'), 2, ',', ' ') }} м2
                                </th>
                                <th></th>
                                <th></th>
                                <th class="text-end text-nowrap text-success" style="font-size:.9rem;padding:.3rem .4rem .3rem .25rem">
                                    {{ number_format($totalPay, 0, ',', ' ') }} ₽
                                </th>
                            </tr>
                            </tfoot>
                        </table>
                    @endif
                </div>
            </div>
            @endif

            {{-- Детализация --}}
            <div class="card shadow-sm">
                <div class="card-header bg-white p-0">
                    <div class="d-flex w-100" style="background:#e9ecef;padding:4px;gap:3px;min-width:0">
                        <button type="button" id="view-btn-batches"
                                style="flex:1;min-width:0;border:none;border-radius:4px;padding:.35rem .25rem;font-size:.75rem;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;background:transparent;color:#6c757d;transition:all .15s">
                            По партиям
                        </button>
                        <button type="button" id="view-btn-logs"
                                style="flex:1;min-width:0;border:none;border-radius:4px;padding:.35rem .25rem;font-size:.75rem;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;background:transparent;color:#6c757d;transition:all .15s">
                            По приёмкам
                        </button>
                        <button type="button" id="view-btn-raw"
                                style="flex:1;min-width:0;border:none;border-radius:4px;padding:.35rem .25rem;font-size:.75rem;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;background:transparent;color:#6c757d;transition:all .15s">
                            Сырьё@php $activeRawCount = $rawBatches->whereIn('status', ['new','in_work'])->count() @endphp
                            @if($activeRawCount) <span class="badge bg-success" style="font-size:.6rem;vertical-align:middle">{{ $activeRawCount }}</span>@endif
                        </button>
                    </div>
                </div>

                <div style="padding:.25rem">

                    {{-- ═══ ВИД: ПО ПАРТИЯМ (StoneReception) ═══ --}}
                    <div id="view-batches">
                        @forelse($stoneReceptions as $reception)
                            @include('partials.reception-card', [
                                'reception'   => $reception,
                                'showActions' => auth()->user()->isAdmin() || auth()->user()->isMaster(),
                            ])
                        @empty
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                                Приёмок за период нет
                            </div>
                        @endforelse
                    </div>

                    {{-- ═══ ВИД: ПО ПРИЁМКАМ (ReceptionLog) ═══ --}}
                    <div id="view-logs">
                        @forelse($receptions as $log)
                            @include('partials.reception-log-card', [
                                'log'             => $log,
                                'showActions'     => true,
                                'showRawDetails'  => false,
                                'showStoreBottom' => true,
                                'isMaster'        => $isMaster,
                            ])
                        @empty
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                                Записей за период нет
                            </div>
                        @endforelse
                    </div>

                    {{-- ═══ ВИД: СЫРЬЁ (RawMaterialBatch) ═══ --}}
                    <div id="view-raw">
                        @forelse($rawBatches as $batch)
                            @include('partials.raw-batch-card', ['batch' => $batch])
                        @empty
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                                Партий сырья за период нет
                            </div>
                        @endforelse
                    </div>

                </div>
            </div>

        @endif
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {

            // ── Сворачиваемый блок периода ────────────────────────────────────────
            (function () {
                const STORAGE_KEY = 'dashboard_period_open';
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

            // ── Переключатель вида ────────────────────────────────────────────────
            (function () {
                const STORAGE_KEY = 'dashboard_receptions_view';
                const views = {
                    batches: { btn: document.getElementById('view-btn-batches'), el: document.getElementById('view-batches') },
                    logs:    { btn: document.getElementById('view-btn-logs'),    el: document.getElementById('view-logs') },
                    raw:     { btn: document.getElementById('view-btn-raw'),     el: document.getElementById('view-raw') },
                };

                function applyView(view) {
                    Object.entries(views).forEach(([key, { btn, el }]) => {
                        const active = key === view;
                        if (el) el.style.display = active ? '' : 'none';
                        if (btn) {
                            btn.style.background = active ? '#0d6efd' : 'transparent';
                            btn.style.color      = active ? '#fff' : '#0d6efd';
                            btn.style.boxShadow  = active ? '0 1px 3px rgba(0,0,0,.2)' : 'none';
                            btn.style.fontWeight = active ? '600' : '500';
                            btn.style.border     = active ? 'none' : '1.5px solid #0d6efd';
                        }
                    });
                }

                const saved = localStorage.getItem(STORAGE_KEY) || 'batches';
                applyView(saved);

                Object.entries(views).forEach(([key, { btn }]) => {
                    if (btn) btn.addEventListener('click', () => {
                        applyView(key);
                        localStorage.setItem(STORAGE_KEY, key);
                        btn.blur();
                    });
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
