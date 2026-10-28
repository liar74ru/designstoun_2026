@extends('layouts.app')

@section('title', 'Настройки системы')

@section('content')
<div class="container py-3" style="max-width:900px">

    <x-page-header title="Настройки системы" mobileTitle="Настройки">
        <x-slot name="actions">
            @include('partials.help-button', ['page' => 'settings'])
        </x-slot>
        <x-slot name="mobileActions">
            @include('partials.help-button', ['page' => 'settings', 'class' => 'btn-outline-secondary btn-sm'])
        </x-slot>
    </x-page-header>

    @include('partials.alerts')

    @php
        $tiles = [
            [
                'route'   => route('admin.departments.index'),
                'icon'    => 'bi-diagram-3',
                'title'   => 'Отделы',
                'summary' => 'Активных: ' . $activeDepartments . ' · права, ставки, правила, склады, шаблоны цеха',
            ],
            [
                'route'   => route('admin.settings.rates'),
                'icon'    => 'bi-cash-coin',
                'title'   => 'Ставки по умолчанию',
                'summary' => 'Пильщик ' . number_format($pieceRate, 0, ',', ' ') . ' ₽ · мастер '
                    . number_format($masterRate, 0, ',', ' ') . ' ₽ — для отделов без своих',
            ],
            [
                'route'   => route('admin.settings.moysklad'),
                'icon'    => 'bi-cloud-arrow-up',
                'title'   => 'МойСклад',
                'summary' => 'Статусы техопераций: «' . ($inWorkState ?: '—') . '» / «' . ($doneState ?: '—') . '»',
            ],
            [
                'route'   => route('admin.order-states.index'),
                'icon'    => 'bi-list-check',
                'title'   => 'Статусы заявок',
                'summary' => 'Какие статусы заявок МойСклад использует программа и как',
            ],
            [
                'route'   => route('admin.settings.sku-colors'),
                'icon'    => 'bi-palette',
                'title'   => 'Цвета по SKU',
                'summary' => $skuColorCount > 0
                    ? 'Цветов: ' . $skuColorCount . ' · полоса и фон карточек по типу камня'
                    : 'Не заданы — все карточки белые',
            ],
        ];
    @endphp

    <div class="row g-2 g-md-3">
        @foreach($tiles as $tile)
            <div class="col-12 col-md-6">
                <a href="{{ $tile['route'] }}" class="card shadow-sm h-100 text-decoration-none text-body settings-tile">
                    <div class="card-body d-flex align-items-center gap-3 py-3">
                        <i class="bi {{ $tile['icon'] }} text-primary fs-3 flex-shrink-0"></i>
                        <div class="flex-grow-1 min-w-0">
                            <div class="fw-semibold">{{ $tile['title'] }}</div>
                            <div class="text-muted small">{{ $tile['summary'] }}</div>
                        </div>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

</div>

<style>
.settings-tile { transition: background-color .15s ease, border-color .15s ease; }
.settings-tile:hover { background-color: rgba(var(--bs-primary-rgb), .04); border-color: rgba(var(--bs-primary-rgb), .35); }
</style>
@endsection
