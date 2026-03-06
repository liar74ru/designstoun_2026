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
            <form method="GET" class="d-flex gap-2 align-items-end flex-wrap">
                <div>
                    <label class="form-label mb-1 small text-muted">С</label>
                    <input type="date" name="date_from" class="form-control form-control-sm"
                           value="{{ $dateFrom->format('Y-m-d') }}">
                </div>
                <div>
                    <label class="form-label mb-1 small text-muted">По</label>
                    <input type="date" name="date_to" class="form-control form-control-sm"
                           value="{{ $dateTo->format('Y-m-d') }}">
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Показать</button>
                <a href="{{ request()->url() }}" class="btn btn-outline-secondary btn-sm">
                    Текущая неделя
                </a>
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
                    <button class="btn btn-sm btn-outline-secondary"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#receptionsList">
                        Свернуть / развернуть
                    </button>
                </div>
                <div class="collapse show" id="receptionsList">
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
