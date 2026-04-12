@extends('layouts.app')

@section('title', 'Дашборд мастера — ' . $worker->name)

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

        {{-- Итого к выплате --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-2 px-3">
                <div class="text-muted small mb-1">Итого к выплате</div>
                <div class="fs-4 fw-bold text-secondary">—</div>
            </div>
        </div>

        @if($summary->isEmpty())
            <div class="alert alert-info">
                За выбранный период приёмок не найдено.
            </div>
        @else

            {{-- Сводка по продуктам --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header fw-semibold py-2 d-flex justify-content-between align-items-center">
                    <span>Сводка по продуктам за период</span>
                </div>
                <div class="table-responsive">
                    <table class="table mb-0" style="font-size:.75rem;table-layout:auto">
                        <colgroup>
                            <col>
                            <col style="width:1%">
                        </colgroup>
                        <thead class="table-light">
                            <tr>
                                <th style="border-left:4px solid transparent;padding:.3rem .1rem .3rem .4rem">Плитка</th>
                                <th class="text-end text-nowrap" style="border-right:4px solid transparent;padding:.3rem .4rem .3rem .25rem">м²</th>
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
                                </td>
                                <td class="text-end text-nowrap fw-semibold" style="border-right:4px solid {{ $skuColor }};{{ $skuBg }};padding:.3rem .4rem .3rem .25rem">
                                    {{ number_format($row['quantity'], 3, ',', ' ') }}
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th class="text-end fw-bold" style="padding:.3rem .25rem">ИТОГО:</th>
                                <th class="text-end text-nowrap" style="font-size:.9rem;padding:.3rem .4rem .3rem .25rem">
                                    {{ number_format($totalQuantity, 3, ',', ' ') }}
                                </th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            {{-- Детализация: приёмки + сырьё --}}
            <div class="card shadow-sm">
                <div class="card-header bg-white p-0">
                    <div class="d-flex w-100" style="background:#e9ecef;padding:4px;gap:3px;min-width:0">
                        <button type="button" id="view-btn-receptions"
                                style="flex:1;min-width:0;border:none;border-radius:4px;padding:.35rem .25rem;font-size:.75rem;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;background:transparent;color:#6c757d;transition:all .15s">
                            По приёмкам
                        </button>
                        <button type="button" id="view-btn-raw"
                                style="flex:1;min-width:0;border:none;border-radius:4px;padding:.35rem .25rem;font-size:.75rem;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;background:transparent;color:#6c757d;transition:all .15s">
                            Сырьё
                        </button>
                    </div>
                </div>

                <div style="padding:.25rem">

                    {{-- ═══ ВИД: ПО ПРИЁМКАМ ═══ --}}
                    <div id="view-receptions">
                        @forelse($stoneReceptions as $reception)
                            @php
                                $skuColor = \App\Models\Product::getColorBySku($reception->rawMaterialBatch?->product?->sku);
                                $skuBg    = $skuColor === '#FFFFFF' ? '#fff' : $skuColor . '18';
                            @endphp
                            <div style="margin-bottom:.35rem;border-radius:.35rem;border:1px solid #dee2e6;border-left:4px solid {{ $skuColor }};border-right:4px solid {{ $skuColor }};background:{{ $skuBg }};box-shadow:0 1px 2px rgba(0,0,0,.07)">
                                <div style="padding:.2rem .35rem">

                                    {{-- Дата + статус --}}
                                    <div class="d-flex justify-content-between align-items-center" style="margin-bottom:.2rem">
                                        <span class="text-muted" style="font-size:.72rem">
                                            {{ $reception->created_at->format('d.m.Y H:i') }}
                                        </span>
                                        <div class="d-flex gap-1 align-items-center">
                                            <a href="{{ route('stone-receptions.show', $reception) }}"
                                               class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center"
                                               style="width:22px;height:22px;padding:0;font-size:.65rem" title="Открыть приёмку">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            @if($reception->status === 'active')
                                                <span class="badge bg-success" style="font-size:.65rem">Активна</span>
                                            @elseif($reception->status === 'completed')
                                                <span class="badge bg-warning text-dark" style="font-size:.65rem">Завершена</span>
                                            @elseif($reception->status === 'processed')
                                                <span class="badge bg-secondary" style="font-size:.65rem">Обработана</span>
                                            @elseif($reception->status === 'error')
                                                <span class="badge bg-danger" style="font-size:.65rem">Ошибка</span>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Продукция --}}
                                    @if($reception->items->count() > 0)
                                        <div style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem;margin-bottom:.2rem">
                                            @foreach($reception->items as $item)
                                                <div class="d-flex justify-content-between align-items-baseline" style="{{ !$loop->last ? 'margin-bottom:.1rem' : '' }}">
                                                    <span class="text-truncate me-2" style="font-size:.72rem;max-width:80%">
                                                        <i class="bi bi-grid-3x3 text-secondary me-1"></i>{{ $item->product->name }}
                                                    </span>
                                                    <span class="fw-semibold text-primary text-nowrap" style="font-size:.72rem">
                                                        {{ number_format($item->quantity, 3, ',', '.') }} м²
                                                    </span>
                                                </div>
                                            @endforeach
                                            <div class="d-flex justify-content-end" style="margin-top:.1rem">
                                                <span class="text-muted text-nowrap" style="font-size:.7rem">
                                                    Итого: <span class="fw-semibold text-primary">{{ number_format($reception->total_quantity, 3, ',', '.') }} м²</span>
                                                </span>
                                            </div>
                                        </div>
                                    @endif

                                    {{-- Сырьё (партия) --}}
                                    @if($reception->rawMaterialBatch)
                                        @php
                                            $bInit = (float) ($reception->rawMaterialBatch->initial_quantity ?? 0);
                                            $bRem  = (float) ($reception->rawMaterialBatch->remaining_quantity ?? 0);
                                        @endphp
                                        <div class="d-flex justify-content-between align-items-center" style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem;margin-bottom:.2rem">
                                            <span class="text-muted text-truncate me-2" style="font-size:.72rem">
                                                <i class="bi bi-box me-1"></i>{{ $reception->rawMaterialBatch->product->name ?? '?' }}
                                            </span>
                                            <div class="d-flex gap-1 flex-shrink-0 align-items-center">
                                                <span style="font-size:.6rem;color:#6c757d;white-space:nowrap">нач.</span>
                                                <span style="font-size:.65rem;padding:1px 4px;border-radius:3px;background:#e9ecef;color:#495057;white-space:nowrap">{{ number_format($bInit, 3, '.', '') }}</span>
                                                <span style="font-size:.6rem;color:#6c757d;white-space:nowrap">ост.</span>
                                                <span style="font-size:.65rem;padding:1px 4px;border-radius:3px;background:{{ $bRem > 0 ? '#cff4fc' : '#fff3cd' }};color:{{ $bRem > 0 ? '#055160' : '#664d03' }};white-space:nowrap">{{ number_format($bRem, 3, '.', '') }}</span>
                                            </div>
                                        </div>
                                    @endif

                                    {{-- Пильщик + склад --}}
                                    <div class="d-flex justify-content-between align-items-center" style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem">
                                        <span class="text-muted" style="font-size:.65rem">
                                            <i class="bi bi-building me-1"></i>{{ $reception->store?->name ?? '—' }}
                                        </span>
                                        @if($reception->cutter)
                                            <span class="text-muted" style="font-size:.65rem">
                                                <i class="bi bi-person me-1"></i>{{ $reception->cutter->name }}
                                            </span>
                                        @endif
                                    </div>

                                </div>
                            </div>
                        @empty
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                                Приёмок за период нет
                            </div>
                        @endforelse
                    </div>

                    {{-- ═══ ВИД: СЫРЬЁ ═══ --}}
                    <div id="view-raw">
                        @forelse($rawBatches as $batch)
                            @php
                                $skuColor = \App\Models\Product::getColorBySku($batch->product?->sku);
                                $skuBg    = $skuColor === '#FFFFFF' ? '#fff' : $skuColor . '18';
                                $bInit    = (float) ($batch->initial_quantity ?? 0);
                                $bRem     = (float) ($batch->remaining_quantity ?? 0);
                                $isActive = in_array($batch->status, ['new', 'in_work']);
                            @endphp
                            <div style="margin-bottom:.35rem;border-radius:.35rem;border:1px solid #dee2e6;border-left:4px solid {{ $skuColor }};border-right:4px solid {{ $skuColor }};background:{{ $skuBg }};box-shadow:0 1px 2px rgba(0,0,0,.07);{{ !$isActive ? 'opacity:.75' : '' }}">
                                <div style="padding:.2rem .35rem">

                                    <div class="d-flex justify-content-between align-items-center" style="margin-bottom:.2rem">
                                        @if($batch->batch_number)
                                            <span class="text-muted" style="font-size:.72rem">№{{ $batch->batch_number }}</span>
                                        @else
                                            <span></span>
                                        @endif
                                        <span class="badge {{ $batch->statusBadgeClass() }}" style="font-size:.65rem">
                                            {{ $batch->statusLabel() }}
                                        </span>
                                    </div>

                                    <div class="d-flex justify-content-between align-items-start" style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem;margin-bottom:.2rem">
                                        <span class="fw-semibold me-2" style="font-size:.75rem">
                                            <i class="bi bi-box text-secondary me-1"></i>{{ $batch->product?->name ?? '—' }}
                                        </span>
                                        <div class="d-flex flex-column align-items-end flex-shrink-0" style="gap:.1rem">
                                            <div class="d-flex gap-1 align-items-center">
                                                <span style="font-size:.6rem;color:#6c757d;white-space:nowrap">нач.</span>
                                                <span style="font-size:.65rem;padding:1px 4px;border-radius:3px;background:#e9ecef;color:#495057;white-space:nowrap">{{ number_format($bInit, 3, '.', '') }}</span>
                                            </div>
                                            <div class="d-flex gap-1 align-items-center">
                                                <span style="font-size:.6rem;color:#6c757d;white-space:nowrap">ост.</span>
                                                <span style="font-size:.65rem;padding:1px 4px;border-radius:3px;background:{{ $bRem > 0 ? '#cff4fc' : '#fff3cd' }};color:{{ $bRem > 0 ? '#055160' : '#664d03' }};white-space:nowrap">{{ number_format($bRem, 3, '.', '') }}</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-flex justify-content-between align-items-center" style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem;margin-bottom:.2rem">
                                        <span class="fw-bold" style="font-size:.72rem">
                                            <i class="bi bi-calendar3 text-secondary me-1"></i>{{ $batch->created_at->format('d.m.Y H:i') }}
                                        </span>
                                        @if($batch->currentStore)
                                            <span class="text-muted" style="font-size:.65rem">
                                                <i class="bi bi-building me-1"></i>{{ $batch->currentStore->name }}
                                            </span>
                                        @endif
                                    </div>

                                    <div class="d-flex gap-1 justify-content-end" style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem">
                                        <a href="{{ route('raw-batches.show', $batch) }}"
                                           class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center"
                                           style="width:22px;height:22px;padding:0;font-size:.65rem" title="Посмотреть партию">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        @if($isActive)
                                            <a href="{{ route('raw-batches.adjust-remaining.form', $batch) }}"
                                               class="btn btn-outline-warning d-inline-flex align-items-center justify-content-center"
                                               style="width:22px;height:22px;padding:0;font-size:.65rem" title="Откорректировать остаток">
                                                <i class="bi bi-pencil-square"></i>
                                            </a>
                                        @endif
                                        @if($isActive && $bRem <= 0)
                                            <form method="POST" action="{{ route('raw-batches.mark-used', $batch) }}" class="d-inline-flex"
                                                  onsubmit="return confirm('Завершить партию? Сырьё будет отмечено как израсходованное.')">
                                                @csrf
                                                <button type="submit"
                                                        class="btn btn-warning d-inline-flex align-items-center justify-content-center"
                                                        style="width:22px;height:22px;padding:0;font-size:.65rem" title="Завершить партию">
                                                    <i class="bi bi-check2-circle"></i>
                                                </button>
                                            </form>
                                        @endif
                                    </div>

                                </div>
                            </div>
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
                const STORAGE_KEY = 'master_dashboard_period_open';
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

            // ── Переключатель вида: по приёмкам / сырьё ──────────────────────────
            (function () {
                const STORAGE_KEY = 'master_dashboard_view';
                const views = {
                    receptions: { btn: document.getElementById('view-btn-receptions'), el: document.getElementById('view-receptions') },
                    raw:        { btn: document.getElementById('view-btn-raw'),        el: document.getElementById('view-raw') },
                };

                function applyView(view) {
                    Object.entries(views).forEach(([key, { btn, el }]) => {
                        const active = key === view;
                        if (el)  el.style.display  = active ? '' : 'none';
                        if (btn) {
                            btn.style.background = active ? '#0d6efd' : 'transparent';
                            btn.style.color      = active ? '#fff' : '#0d6efd';
                            btn.style.boxShadow  = active ? '0 1px 3px rgba(0,0,0,.2)' : 'none';
                            btn.style.fontWeight = active ? '600' : '500';
                            btn.style.border     = active ? 'none' : '1.5px solid #0d6efd';
                        }
                    });
                }

                const saved = localStorage.getItem(STORAGE_KEY) || 'receptions';
                applyView(saved);

                Object.entries(views).forEach(([key, { btn }]) => {
                    if (btn) btn.addEventListener('click', () => {
                        applyView(key);
                        localStorage.setItem(STORAGE_KEY, key);
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
