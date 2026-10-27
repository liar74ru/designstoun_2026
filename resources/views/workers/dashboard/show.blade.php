@extends('layouts.app')

@section('title', 'Выработка — ' . $worker->name)

@section('content')
    <div class="container py-3 py-md-4">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="fs-2 mb-0 fw-bold">{{ $worker->name }}</h1>
            <div class="d-flex gap-1">
                @include('partials.help-button', ['page' => 'pay', 'class' => 'btn-sm btn-outline-secondary'])
                @if($worker->user)
                    <a href="{{ route('workers.edit-user', $worker) }}"
                       class="btn btn-sm btn-outline-secondary"
                       title="Учётная запись / смена пароля">
                        <i class="bi bi-key"></i>
                    </a>
                @endif
            </div>
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

        @if($salary)
            @include('partials.salary-piggy-bank', ['worker' => $worker, 'salary' => $salary])
        @endif

        @if($departments->isEmpty())
            <div class="alert alert-info">
                За выбранный период приёмок не найдено.
            </div>
        @else

            @if($canSeePay)
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body py-2 px-3">
                        <div class="text-muted small mb-1">Итого к выплате</div>
                        <div class="fs-4 fw-bold text-success">
                            {{ number_format($totalPay, 0, ',', ' ') }} ₽
                        </div>
                        @if($totalWorkerPay && $totalMasterPay)
                            <div class="small text-muted">
                                работник {{ number_format($totalWorkerPay, 0, ',', ' ') }} ₽
                                · мастер {{ number_format($totalMasterPay, 0, ',', ' ') }} ₽
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Сводка по продуктам: карточка на отдел документа, внутри — таблица на роль.
                 Ставки настраиваются per-department, поэтому показываются в карточке отдела. --}}
            <div class="fw-semibold mb-2">Сводка по продуктам за период</div>
            @foreach($departments as $dept)
                <div class="card shadow-sm mb-3">
                    <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap gap-1">
                        <span class="fw-semibold">
                            <i class="bi bi-building me-1"></i>{{ $dept['department']?->name ?? 'Без отдела' }}
                        </span>
                        @if($canSeePay)
                            <span class="small text-success">{{ number_format($dept['totalPay'], 0, ',', ' ') }} ₽</span>
                        @endif
                    </div>
                    {{-- Ставка и заголовок роли — только по ролям, в которых человек работал:
                         пильщику ставку мастера знать незачем, а единственная роль подписи не требует --}}
                    @php
                        $deptRates = array_filter([
                            $dept['worker'] ? 'пильщика ' . number_format($dept['rates']['piece'], 0, ',', ' ') . ' ₽/ед' : null,
                            $dept['master'] ? 'база мастера ' . number_format($dept['rates']['base'], 0, ',', ' ') . ' ₽/м²' : null,
                        ]);
                        $bothRoles = $dept['worker'] && $dept['master'];
                    @endphp
                    @if($canSeePay && $deptRates)
                        <div class="px-3 py-1 border-bottom text-muted" style="font-size:.75rem">
                            Ставки отдела: {{ implode(', ', $deptRates) }}
                            (без коэффициента продукта)
                        </div>
                    @endif

                    @foreach([
                        'worker' => ['Как работник', 'bi-hammer', 'totalPay', 'partials.dashboard-worker-summary-table'],
                        'master' => ['Как мастер', 'bi-clipboard-check', 'totalMasterPay', 'partials.dashboard-master-summary-table'],
                    ] as $role => [$roleLabel, $roleIcon, $payKey, $table])
                        @continue(!$dept[$role])
                        @if($bothRoles)
                            <div class="px-3 py-1 bg-light border-bottom d-flex justify-content-between align-items-center small">
                                <span class="fw-semibold"><i class="bi {{ $roleIcon }} me-1"></i>{{ $roleLabel }}</span>
                                <span class="text-muted">
                                    <span class="me-2">{{ number_format($dept[$role]['totalQuantity'], 3, ',', ' ') }} м²</span>
                                    @if($canSeePay)
                                        <span class="text-success">{{ number_format($dept[$role][$payKey], 0, ',', ' ') }} ₽</span>
                                    @endif
                                </span>
                            </div>
                        @endif
                        <div class="table-responsive">
                            @include($table, [
                                'rows'           => $dept[$role]['summary'],
                                'totalQuantity'  => $dept[$role]['totalQuantity'],
                                'totalPay'       => $dept[$role]['totalPay'],
                                'totalMasterPay' => $dept[$role]['totalMasterPay'],
                                'showPay'        => $canSeePay,
                            ])
                        </div>
                    @endforeach
                </div>
            @endforeach

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
                                'isMaster'        => $log->receiver_id === $worker->id,
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
