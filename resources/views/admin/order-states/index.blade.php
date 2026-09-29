@extends('layouts.app')

@section('title', 'Статусы заявок')

@php
    // Колонки справочника: поле формы, флаг модели, заголовок колонки (десктоп),
    // подпись галочки (телефон), подсказка. Шапка, подписи и поля — из одного списка.
    $columns = [
        ['name' => 'enabled[]',        'flag' => 'is_enabled',        'short' => 'Исп.',     'label' => 'Используется',        'title' => 'Подгружать заявки в этом статусе'],
        ['name' => 'production[]',     'flag' => 'is_production',     'short' => 'Произв.',  'label' => 'Производство',        'title' => 'В этом статусе идёт производство'],
        ['name' => 'default_filter[]', 'flag' => 'is_default_filter', 'short' => 'В списке', 'label' => 'В списке',            'title' => 'Показывать в списке заявок по умолчанию'],
        ['name' => 'list_bottom[]',    'flag' => 'is_list_bottom',    'short' => 'Вниз',     'label' => 'В конец списка',      'title' => 'Показывать заявки в этом статусе в конце списка'],
        ['name' => 'track_changes[]',  'flag' => 'track_changes',     'short' => 'Следить',  'label' => 'Следить за составом', 'title' => 'Замечать изменение позиций заявок в этом статусе'],
    ];
@endphp

@push('styles')
<style>
    /*
     * Один набор полей на оба режима: на телефоне строка — карточка (плашка сверху,
     * подписанные галочки в два столбца), с md — строка таблицы с колонками.
     * Дубли разметки d-none/d-md-none не годятся: в форму ушли бы две копии галочек.
     */
    .os-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: .4rem .75rem;
        padding: .65rem .75rem;
        border-bottom: 1px solid #f1f3f5;
        margin: 0;
    }
    .os-row:last-child { border-bottom: 0; }
    .os-name {
        grid-column: 1 / -1;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .35rem;
        min-width: 0;
    }
    .os-meta { font-size: .72rem; }
    .os-cell {
        display: flex;
        align-items: center;
        gap: .45rem;
        margin: 0;
        cursor: pointer;
        font-size: .82rem;
        color: #495057;
    }
    .os-cell .form-check-input { margin: 0; flex-shrink: 0; }
    .os-head { display: none; }

    /* «Статуса «Изменено» нет»: на телефоне — одна строка «радио + текст» */
    .os-row-none { display: flex; align-items: center; gap: .5rem; cursor: pointer; color: #6c757d; font-size: .82rem; }
    .os-row-none .os-cell { order: -1; }

    @media (min-width: 768px) {
        .os-row,
        .os-head {
            grid-template-columns: minmax(0, 1fr) repeat(6, 76px);
            align-items: center;
            gap: 0 .25rem;
        }
        .os-head {
            display: grid;
            padding: .45rem .75rem;
            font-size: .72rem;
            color: #6c757d;
            border-bottom: 1px solid #e9ecef;
            text-align: center;
        }
        .os-head > :first-child { text-align: left; }
        .os-name { grid-column: auto; }
        .os-cell { justify-content: center; }
        .os-cell-label { display: none; }
        .os-row-none { display: grid; }
        .os-row-none .os-cell { order: 0; grid-column: 7; }
    }
</style>
@endpush

@section('content')
<div class="container py-3 py-md-4" style="max-width:900px">

    <x-page-header
        title="Статусы заявок"
        mobileTitle="Статусы заявок"
        backUrl="{{ route('admin.settings.index') }}"
        backLabel="К настройкам">
        <x-slot name="actions">
            @include('partials.help-button', ['page' => 'settings'])
            <form method="POST" action="{{ route('admin.order-states.sync') }}" data-submit-guard>
                @csrf
                <button type="submit" class="btn btn-outline-primary">
                    <i class="bi bi-arrow-repeat"></i> Обновить из МойСклад
                </button>
            </form>
        </x-slot>
        <x-slot name="mobileActions">
            @include('partials.help-button', ['page' => 'settings', 'class' => 'btn-outline-secondary btn-sm'])
            <form method="POST" action="{{ route('admin.order-states.sync') }}" data-submit-guard>
                @csrf
                <button type="submit" class="btn btn-outline-primary btn-sm" title="Обновить из МойСклад">
                    <i class="bi bi-arrow-repeat"></i>
                </button>
            </form>
        </x-slot>
    </x-page-header>

    @include('partials.alerts')

    <p class="text-muted small mb-3">
        Отметьте, какие статусы МойСклад использует программа и как с ними работать.
        Что значит каждая колонка — в справке <i class="bi bi-question-circle"></i>.
    </p>

    @if($states->isEmpty())
        <div class="text-center py-5">
            <i class="bi bi-list-check display-4 text-muted"></i>
            <h5 class="text-muted mt-3">Статусы не загружены</h5>
            <p class="text-muted">Нажмите «Обновить из МойСклад», чтобы получить список.</p>
        </div>
    @else
        <form method="POST" action="{{ route('admin.order-states.update') }}" data-submit-guard>
            @csrf

            <div class="info-block">
                <div class="info-block-header d-flex justify-content-between align-items-center small fw-semibold">
                    <span>Статусы МойСклад</span>
                    <span class="badge bg-secondary">{{ $states->count() }}</span>
                </div>
                <div class="info-block-body p-0">
                    <div class="os-head">
                        <span>Статус</span>
                        @foreach($columns as $column)
                            <span title="{{ $column['title'] }}">{{ $column['short'] }}</span>
                        @endforeach
                        <span title="Это статус «Изменено»">Изм.</span>
                    </div>

                    {{-- Одно поле в строке — вся строка кликабельна --}}
                    <label class="os-row os-row-none">
                        <span class="os-name">Статуса «Изменено» нет</span>
                        <span class="os-cell">
                            <input type="radio" class="form-check-input" name="changed_state" value=""
                                   {{ $states->contains('is_changed', true) ? '' : 'checked' }}>
                        </span>
                    </label>

                    {{-- Независимые галочки: строка не обёрнута в общий <label>,
                         он переключал бы только первую --}}
                    @foreach($states as $state)
                        <div class="os-row">
                            <div class="os-name">
                                <span class="badge"
                                      style="background-color: {{ $state->hex_color }}; color: {{ \App\Support\BadgeColor::textFor($state->hex_color) }}">
                                    {{ $state->name }}
                                </span>
                                @if($state->archived)
                                    <span class="badge bg-danger-subtle text-danger-emphasis"
                                          title="Статуса больше нет в МойСклад">нет в МойСклад</span>
                                @endif
                                @if($state->state_type && $state->state_type !== 'Regular')
                                    <span class="text-muted os-meta">{{ $state->state_type }}</span>
                                @endif
                            </div>

                            @foreach($columns as $column)
                                <label class="os-cell" title="{{ $column['title'] }}">
                                    <input type="checkbox" class="form-check-input"
                                           name="{{ $column['name'] }}" value="{{ $state->id }}"
                                           {{ $state->{$column['flag']} ? 'checked' : '' }}>
                                    <span class="os-cell-label">{{ $column['label'] }}</span>
                                </label>
                            @endforeach

                            <label class="os-cell" title="Это статус «Изменено»">
                                <input type="radio" class="form-check-input"
                                       name="changed_state" value="{{ $state->id }}"
                                       {{ $state->is_changed ? 'checked' : '' }}>
                                <span class="os-cell-label">Статус «Изменено»</span>
                            </label>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="py-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> Сохранить
                </button>
                <a href="{{ route('admin.settings.index') }}" class="btn btn-outline-secondary">Отмена</a>
            </div>
        </form>
    @endif

</div>
@endsection
