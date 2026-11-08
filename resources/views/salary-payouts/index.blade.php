{{-- Сводка выплат за неделю по отделам (App\Services\SalaryPayoutReportService::report()) --}}
@extends('layouts.app')

@section('title', 'Выплаты')

@section('content')
@php
    // Доли отделов дают копейки — показываем их, только когда они есть
    $rub = fn ($v) => number_format((float) $v, fmod(round((float) $v, 2), 1) != 0 ? 2 : 0, ',', ' ') . ' ₽';
    $cell = fn ($v) => (float) $v != 0 ? $rub($v) : '—';
    $bankCls = fn ($v) => $v < 0 ? 'text-danger' : ($v > 0 ? 'text-success' : 'text-muted');
    $weekLabel = fn ($to) => $to->copy()->subWeek()->format('d.m') . ' — ' . $to->copy()->subSecond()->format('d.m.Y');
    $columns = array_filter([
        'accrued' => 'Начислено',
        'recalc'  => $hasRecalc ? 'Перерасчёт' : null,
        'cash'    => 'Наличные',
        'card'    => 'Карта',
        'tax'     => 'Налог',
        'toBank'  => 'В копилку',
        'total'   => 'Итого выдано',
    ]);
@endphp
<div class="container py-3">

    <x-page-header title="Выплаты" mobile-title="Выплаты">
        <x-slot name="actions">
            @include('partials.help-button', ['page' => 'pay'])
        </x-slot>
        <x-slot name="mobileActions">
            @include('partials.help-button', ['page' => 'pay', 'class' => 'btn-outline-secondary btn-sm'])
        </x-slot>
    </x-page-header>

    @include('partials.alerts')

    @if($week)
        {{-- Неделя выдачи: период выдачи заканчивается в пятницу 00:00 --}}
        <div class="dropdown mb-3">
            <button class="btn btn-outline-primary dropdown-toggle w-100 text-start" type="button"
                    data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-calendar-week me-1"></i>Неделя {{ $weekLabel($week) }}
            </button>
            <ul class="dropdown-menu w-100 shadow-sm" style="max-height:60vh;overflow-y:auto">
                @foreach($weeks as $w)
                    <li>
                        <a class="dropdown-item {{ $w->isSameDay($week) ? 'active' : '' }}"
                           href="{{ route('salary-payouts.index', ['week' => $w->toDateString()]) }}">
                            {{ $weekLabel($w) }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>

        <p class="small text-muted mb-3">
            Работник, заработавший в нескольких отделах, стоит в каждом из них: выплата и копилка
            делятся пропорционально начисленному в отделе. Часы ремонтных работ — в основном отделе.
        </p>

        {{-- Десктоп --}}
        <div class="d-none d-md-block card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover table-sm align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Работник</th>
                        @foreach($columns as $label)
                            <th class="text-end text-nowrap">{{ $label }}</th>
                        @endforeach
                    </tr>
                    </thead>
                    @foreach($groups as $group)
                        <tbody>
                        <tr class="table-secondary">
                            <th>
                                <i class="bi bi-building me-1"></i>{{ $group['department']?->name ?? 'Без отдела' }}
                                <span class="fw-normal text-muted small ms-1">{{ $group['rows']->count() }}</span>
                            </th>
                            @foreach($columns as $key => $label)
                                <th class="text-end text-nowrap {{ $key === 'toBank' ? $bankCls($group['totals'][$key]) : '' }}">
                                    {{ $cell($group['totals'][$key]) }}
                                </th>
                            @endforeach
                        </tr>
                        @foreach($group['rows'] as $row)
                            <tr>
                                <td>
                                    <a href="{{ route('worker.dashboard.by-id', $row['worker']->id) }}" class="text-decoration-none">
                                        {{ $row['worker']->name }}
                                    </a>
                                    @if($row['worker']->isArchived())
                                        <span class="badge bg-secondary ms-1"><i class="bi bi-archive"></i></span>
                                    @endif
                                </td>
                                @foreach($columns as $key => $label)
                                    <td class="text-end text-nowrap {{ $key === 'toBank' ? $bankCls($row[$key]) : '' }} {{ $key === 'total' ? 'fw-semibold' : '' }}">
                                        {{ $cell($row[$key]) }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                        </tbody>
                    @endforeach
                    <tfoot class="table-light">
                    <tr>
                        <th>Итого</th>
                        @foreach($columns as $key => $label)
                            <th class="text-end text-nowrap {{ $key === 'toBank' ? $bankCls($totals[$key]) : '' }}">
                                {{ $cell($totals[$key]) }}
                            </th>
                        @endforeach
                    </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        {{-- Мобильный --}}
        <div class="d-md-none">
            @foreach($groups as $group)
                <div class="card shadow-sm mb-3">
                    <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center gap-2">
                        <span class="fw-semibold small">
                            <i class="bi bi-building me-1"></i>{{ $group['department']?->name ?? 'Без отдела' }}
                        </span>
                        <span class="fw-bold small text-nowrap">{{ $rub($group['totals']['total']) }}</span>
                    </div>
                    <ul class="list-group list-group-flush">
                        @foreach($group['rows'] as $row)
                            <li class="list-group-item py-2">
                                <div class="d-flex justify-content-between align-items-baseline gap-2 mb-1">
                                    <a href="{{ route('worker.dashboard.by-id', $row['worker']->id) }}"
                                       class="fw-semibold small text-decoration-none text-truncate">{{ $row['worker']->name }}</a>
                                    <span class="fw-semibold small text-nowrap">{{ $rub($row['total']) }}</span>
                                </div>
                                <div class="row g-1 small">
                                    @foreach($columns as $key => $label)
                                        @continue($key === 'total')
                                        <div class="col-4">
                                            <div class="text-muted" style="font-size:.7rem">{{ $label }}</div>
                                            <div class="text-nowrap {{ $key === 'toBank' ? $bankCls($row[$key]) : '' }}">{{ $cell($row[$key]) }}</div>
                                        </div>
                                    @endforeach
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach

            <div class="card shadow-sm">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between fw-bold small mb-1">
                        <span>Итого</span><span>{{ $rub($totals['total']) }}</span>
                    </div>
                    <div class="row g-1 small">
                        @foreach($columns as $key => $label)
                            @continue($key === 'total')
                            <div class="col-4">
                                <div class="text-muted" style="font-size:.7rem">{{ $label }}</div>
                                <div class="text-nowrap {{ $key === 'toBank' ? $bankCls($totals[$key]) : '' }}">{{ $cell($totals[$key]) }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="text-center py-5">
            <i class="bi bi-cash-stack fs-1 text-muted d-block mb-3"></i>
            <h3 class="text-muted">Выплат пока нет</h3>
            <p class="text-muted">Сводка появится после первой отметки «Зарплата выдана».</p>
        </div>
    @endif

</div>
@endsection
