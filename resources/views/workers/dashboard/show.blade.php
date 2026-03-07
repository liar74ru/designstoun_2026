@extends('layouts.app')

@section('title', 'Моя выработка — ' . $worker->name)

@section('content')
    <div class="container py-4">

        {{-- Заголовок и фильтр периода --}}
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h2 mb-0">⛏️ {{ $worker->name }}</h1>
                <span class="text-muted">{{ $worker->position }}</span>
            </div>

            {{-- Форма выбора периода --}}
            <form method="GET" id="period-form" class="d-flex gap-2 align-items-end flex-wrap">
                {{-- Быстрые кнопки недель --}}
                {{-- Вычисляем даты каждой недели на сервере, чтобы подсветить активную --}}
                @php
                    $weekButtons = [];
                    for ($i = 0; $i <= 2; $i++) {
                        $fri = \Carbon\Carbon::today()->startOfDay();
                        while ($fri->dayOfWeek !== \Carbon\Carbon::FRIDAY) {
                            $fri->subDay();
                        }
                        $fri->subDays($i * 7);
                        $thu = $fri->copy()->addDays(6)->endOfDay();
                        $weekButtons[$i] = ['from' => $fri->format('Y-m-d'), 'to' => $thu->format('Y-m-d')];
                    }
                    $currentFrom = $dateFrom->format('Y-m-d');
                    $currentTo   = $dateTo->format('Y-m-d');
                @endphp

                <div class="d-flex gap-1 flex-wrap">
                    @foreach([0 => 'Текущая неделя', 1 => 'Прошлая неделя', 2 => '2 недели назад'] as $week => $label)
                        @php $isActive = $weekButtons[$week]['from'] === $currentFrom && $weekButtons[$week]['to'] === $currentTo; @endphp
                        <button type="button"
                                class="btn btn-sm week-btn {{ $isActive ? 'btn-primary' : 'btn-outline-secondary' }}"
                                data-week="{{ $week }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                <span class="text-muted small align-self-center">или</span>

                {{-- Произвольный период --}}
                <div>
                    <label class="form-label mb-1 small text-muted">С</label>
                    <input type="date" name="date_from" id="date_from" class="form-control form-control-sm"
                           value="{{ $dateFrom->format('Y-m-d') }}">
                </div>
                <div>
                    <label class="form-label mb-1 small text-muted">По</label>
                    <input type="date" name="date_to" id="date_to" class="form-control form-control-sm"
                           value="{{ $dateTo->format('Y-m-d') }}">
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Показать</button>
            </form>
        </div>

        {{-- Период --}}
        <p class="text-muted mb-4">
            Период:
            <strong>{{ $dateFrom->translatedFormat('d M Y') }}</strong>
            —
            <strong>{{ $dateTo->translatedFormat('d M Y') }}</strong>
            ({{ $receptions->count() }} {{ trans_choice('приёмка|приёмки|приёмок', $receptions->count()) }})
        </p>

        @if($summary->isEmpty())
            <div class="alert alert-info">
                За выбранный период приёмок не найдено.
            </div>
        @else

            {{-- Итоговая карточка --}}
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small mb-1">Итого к выплате</div>
                            <div class="fs-3 fw-bold text-success">
                                {{ number_format($totalPay, 2, ',', ' ') }} ₽
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small mb-1">Позиций продуктов</div>
                            <div class="fs-3 fw-bold">{{ $summary->count() }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small mb-1">Ставка за ед.</div>
                            <div class="fs-3 fw-bold">{{ number_format(\App\Models\Product::PIECE_RATE, 0, ',', ' ') }} ₽</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small mb-1">Приёмок за период</div>
                            <div class="fs-3 fw-bold">{{ $receptions->count() }}</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Сводка по продуктам --}}
            <div class="card shadow-sm mb-4">
                <div class="card-header fw-semibold">Сводка по продуктам</div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>Продукт</th>
                            <th class="text-end">Количество</th>
                            <th class="text-end">Коэф.</th>
                            <th class="text-end">Ставка</th>
                            <th class="text-end">Заработано</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($summary as $row)
                            <tr>
                                <td>{{ $row['product']?->name ?? '—' }}</td>
                                <td class="text-end">{{ number_format($row['quantity'], 3, ',', ' ') }}</td>
                                <td class="text-end text-muted">× {{ number_format($row['coeff'], 4, ',', ' ') }}</td>
                                <td class="text-end text-muted">× {{ number_format(\App\Models\Product::PIECE_RATE, 0, ',', ' ') }} ₽</td>
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
            </div>

            {{-- Детализация: список приёмок --}}
            <div class="card shadow-sm">
                <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                    <span>Приёмки за период</span>
                    <button class="btn btn-sm btn-outline-secondary" id="toggle-receptions" type="button">
                        <i class="bi bi-chevron-up" id="toggle-icon"></i>
                        <span id="toggle-label">Свернуть</span>
                    </button>
                </div>
                <div id="receptionsList">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Дата</th>
                                <th>Склад</th>
                                <th>Приёмщик</th>
                                <th>Продукция</th>
                                <th>Расход сырья</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($receptions as $reception)
                                <tr>
                                    <td class="text-nowrap">
                                        {{ $reception->created_at->format('d.m.Y H:i') }}
                                    </td>
                                    <td>{{ $reception->store?->name ?? '—' }}</td>
                                    <td>{{ $reception->receiver?->name ?? '—' }}</td>
                                    <td>
                                        @foreach($reception->items as $item)
                                            <div class="small">
                                                {{ $item->product?->name ?? '?' }}
                                                <span class="text-muted">×{{ number_format($item->quantity, 3, ',', '.') }}</span>
                                            </div>
                                        @endforeach
                                    </td>
                                    <td class="text-nowrap">
                                        {{ number_format($reception->raw_quantity_used, 3, ',', ' ') }}
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
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
                    list.style.display    = isVisible ? 'none' : '';
                    toggleIcon.className  = isVisible ? 'bi bi-chevron-down' : 'bi bi-chevron-up';
                    toggleLabel.textContent = isVisible ? 'Развернуть' : 'Свернуть';
                });
            }

            // ── Быстрые кнопки недель ─────────────────────────────────────────────
            // Рабочая неделя: пятница–четверг (как в контроллере)
            function getWorkWeek(weeksAgo) {
                const today = new Date();
                today.setHours(0, 0, 0, 0);

                // Находим ближайшую прошедшую (или сегодняшнюю) пятницу
                const friday = new Date(today);
                while (friday.getDay() !== 5) {
                    friday.setDate(friday.getDate() - 1);
                }

                // Смещаем на нужное количество недель назад
                friday.setDate(friday.getDate() - weeksAgo * 7);

                // Четверг = пятница + 6 дней
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
