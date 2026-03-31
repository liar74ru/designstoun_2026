@extends('layouts.app')

@section('title', 'Моя выработка — ' . $worker->name)

@section('content')
    <div class="container py-3 py-md-4">

        <x-page-header
            title="⛏️ {{ $worker->name }}"
            mobileTitle="{{ $worker->name }}"
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
            <div class="card-body py-2 px-3">
                {{-- Быстрые кнопки --}}
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
                {{-- Произвольный период --}}
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
        </form>

        {{-- Период --}}
        <p class="text-muted small mb-3">
            Период: <strong>{{ $dateFrom->translatedFormat('d M Y') }}</strong> — <strong>{{ $dateTo->translatedFormat('d M Y') }}</strong>
            ({{ $receptions->count() }} {{ trans_choice('приёмка|приёмки|приёмок', $receptions->count()) }})
        </p>

        @if($summary->isEmpty())
            <div class="alert alert-info">
                За выбранный период приёмок не найдено.
            </div>
        @else

            {{-- Итоговые карточки --}}
            <div class="row g-2 mb-3">
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2 px-3">
                            <div class="text-muted small mb-1">Итого к выплате</div>
                            <div class="fs-4 fw-bold text-success">
                                {{ number_format($totalPay, 0, ',', ' ') }} ₽
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2 px-3">
                            <div class="text-muted small mb-1">Приёмок</div>
                            <div class="fs-4 fw-bold">{{ $receptions->count() }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2 px-3">
                            <div class="text-muted small mb-1">Позиций продуктов</div>
                            <div class="fs-4 fw-bold">{{ $summary->count() }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body py-2 px-3">
                            <div class="text-muted small mb-1">Базовая ставка</div>
                            <div class="fs-4 fw-bold">{{ number_format(\App\Models\Product::PIECE_RATE, 0, ',', ' ') }} ₽</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Сводка по продуктам --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header fw-semibold py-2">Сводка по продуктам</div>

                {{-- Десктоп --}}
                <div class="d-none d-md-block table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>Продукт</th>
                            <th class="text-end">Количество</th>
                            <th class="text-end" title="Коэффициент зафиксирован на момент приёмки">Коэф. (факт.)</th>
                            <th class="text-end">Ставка</th>
                            <th class="text-end">Заработано</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($summary as $row)
                            @php
                                $rate = floor((\App\Models\Product::PIECE_RATE + \App\Models\Product::PIECE_RATE * 0.17 * $row['coeff']) / 10) * 10;
                            @endphp
                            <tr>
                                <td>{{ $row['product']?->name ?? '—' }}</td>
                                <td class="text-end">{{ number_format($row['quantity'], 3, ',', ' ') }}</td>
                                <td class="text-end text-muted">
                                    × {{ number_format($row['coeff'], 1, ',', ' ') }}
                                    <span class="text-muted small ms-1" title="Коэффициент взят из зафиксированных значений приёмки">🔒</span>
                                </td>
                                <td class="text-end text-muted">{{ number_format($rate, 0, ',', ' ') }} ₽</td>
                                <td class="text-end fw-semibold text-success">
                                    {{ number_format($row['pay'], 2, ',', ' ') }} ₽
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot class="table-light">
                        <tr>
                            <th colspan="4" class="text-end">ИТОГО:</th>
                            <th class="text-end text-success fs-6">
                                {{ number_format($totalPay, 2, ',', ' ') }} ₽
                            </th>
                        </tr>
                        </tfoot>
                    </table>
                </div>

                {{-- Мобильный --}}
                <div class="d-md-none">
                    @foreach($summary as $row)
                        @php
                            $rate     = floor((\App\Models\Product::PIECE_RATE + \App\Models\Product::PIECE_RATE * 0.17 * $row['coeff']) / 10) * 10;
                            $skuColor = \App\Models\Product::getColorBySku($row['product']?->sku);
                            $skuBg    = $skuColor === '#FFFFFF' ? '' : 'background:' . $skuColor . '18;';
                        @endphp
                        <div class="info-block mx-2 my-2" style="border-left:4px solid {{ $skuColor }};border-right:4px solid {{ $skuColor }};{{ $skuBg }}">
                            <div class="info-block-header">
                                <span class="small fw-semibold">{{ $row['product']?->name ?? '—' }}</span>
                            </div>
                            <div class="info-block-body">
                                <div class="d-flex justify-content-between small mb-1">
                                    <span class="text-muted">Количество</span>
                                    <span class="fw-semibold">{{ number_format($row['quantity'], 3, ',', ' ') }} м²</span>
                                </div>
                                <div class="d-flex justify-content-between small mb-1">
                                    <span class="text-muted">Коэф. 🔒</span>
                                    <span>× {{ number_format($row['coeff'], 1, ',', ' ') }}</span>
                                </div>
                                <div class="d-flex justify-content-between small mb-1">
                                    <span class="text-muted">Ставка</span>
                                    <span>{{ number_format($rate, 0, ',', ' ') }} ₽</span>
                                </div>
                                <div class="d-flex justify-content-between small">
                                    <span class="text-muted">Заработано</span>
                                    <span class="fw-bold text-success">{{ number_format($row['pay'], 2, ',', ' ') }} ₽</span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                    <div class="d-flex justify-content-between fw-bold px-3 py-2 border-top">
                        <span>ИТОГО:</span>
                        <span class="text-success">{{ number_format($totalPay, 2, ',', ' ') }} ₽</span>
                    </div>
                </div>
            </div>

            {{-- Детализация: список приёмок --}}
            <div class="card shadow-sm">
                <div class="card-header fw-semibold d-flex justify-content-between align-items-center py-2">
                    <span>Приёмки за период</span>
                    <div class="d-flex gap-2 align-items-center">
                        @if($worker->user)
                            <a href="{{ route('workers.edit-user', $worker) }}"
                               class="btn btn-sm btn-outline-secondary"
                               title="Учётная запись">
                                <i class="bi bi-key"></i>
                            </a>
                        @endif
                        <button class="btn btn-sm btn-outline-secondary" id="toggle-receptions" type="button">
                            <i class="bi bi-chevron-up" id="toggle-icon"></i>
                            <span id="toggle-label" class="d-none d-sm-inline">Свернуть</span>
                        </button>
                    </div>
                </div>
                <div id="receptionsList">

                    {{-- Десктоп --}}
                    <div class="d-none d-md-block table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Дата</th>
                                <th>Склад</th>
                                <th>Приёмщик</th>
                                <th>Продукция (дельта)</th>
                                <th class="text-end">Приёмка #</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($receptions as $log)
                                <tr class="{{ $log->type === 'updated' ? 'table-warning' : '' }}">
                                    <td class="text-nowrap">
                                        {{ $log->created_at->format('d.m.Y H:i') }}
                                        <div class="small">
                                            @if($log->type === 'created')
                                                <span class="badge bg-success">Создание</span>
                                            @else
                                                <span class="badge bg-warning text-dark">Правка</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td>{{ $log->stoneReception?->store?->name ?? '—' }}</td>
                                    <td>{{ $log->receiver?->name ?? '—' }}</td>
                                    <td>
                                        @foreach($log->items as $item)
                                            <div class="small">
                                                {{ $item->product?->name ?? '?' }}
                                                @php $delta = (float) $item->quantity_delta; @endphp
                                                <span class="{{ $delta >= 0 ? 'text-success' : 'text-danger' }} fw-semibold">
                                                    {{ $delta >= 0 ? '+' : '' }}{{ number_format($delta, 3, ',', '.') }}
                                                </span>
                                            </div>
                                        @endforeach
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('stone-receptions.edit', $log->stone_reception_id) }}"
                                           class="btn btn-sm btn-outline-secondary"
                                           title="Редактировать приёмку">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Мобильный --}}
                    <div class="d-md-none">
                        @foreach($receptions as $log)
                            @php
                                $skuColor = \App\Models\Product::getColorBySku($log->rawMaterialBatch?->product?->sku);
                                $skuBg    = $skuColor === '#FFFFFF' ? '' : 'background:' . $skuColor . '18;';
                            @endphp
                            <div class="info-block mx-2 my-2" style="border-left:4px solid {{ $skuColor }};border-right:4px solid {{ $skuColor }};{{ $skuBg }}">
                                <div class="info-block-header d-flex justify-content-between align-items-center">
                                    <span class="small text-muted">{{ $log->created_at->format('d.m.Y H:i') }}</span>
                                    <div class="d-flex gap-1 align-items-center">
                                        @if($log->type === 'created')
                                            <span class="badge bg-success" style="font-size:.65rem">Создание</span>
                                        @else
                                            <span class="badge bg-warning text-dark" style="font-size:.65rem">Правка</span>
                                        @endif
                                        <a href="{{ route('stone-receptions.edit', $log->stone_reception_id) }}"
                                           class="btn btn-sm btn-outline-secondary"
                                           style="width:24px;height:24px;padding:0;font-size:.7rem"
                                           title="Редактировать">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    </div>
                                </div>
                                <div class="info-block-body">
                                    <div class="small text-muted mb-1">
                                        <i class="bi bi-building me-1"></i>{{ $log->stoneReception?->store?->name ?? '—' }}
                                        <span class="ms-2"><i class="bi bi-person me-1"></i>{{ $log->receiver?->name ?? '—' }}</span>
                                    </div>
                                    @foreach($log->items as $item)
                                        @php $delta = (float) $item->quantity_delta; @endphp
                                        <div class="small d-flex justify-content-between">
                                            <span>{{ $item->product?->name ?? '?' }}</span>
                                            <span class="{{ $delta >= 0 ? 'text-success' : 'text-danger' }} fw-semibold">
                                                {{ $delta >= 0 ? '+' : '' }}{{ number_format($delta, 3, ',', '.') }}
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>

                </div>
            </div>

        @endif
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {

            // ── Свернуть / развернуть приёмки ─────────────────────────────────────
            const toggleBtn   = document.getElementById('toggle-receptions');
            const list        = document.getElementById('receptionsList');
            const toggleIcon  = document.getElementById('toggle-icon');
            const toggleLabel = document.getElementById('toggle-label');

            if (toggleBtn && list) {
                toggleBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    const isVisible = list.style.display !== 'none';
                    list.style.display      = isVisible ? 'none' : '';
                    toggleIcon.className    = isVisible ? 'bi bi-chevron-down' : 'bi bi-chevron-up';
                    if (toggleLabel) toggleLabel.textContent = isVisible ? 'Развернуть' : 'Свернуть';
                });
            }

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
