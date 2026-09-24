@extends('layouts.app')

@section('title', 'Статусы заявок')

@section('content')
<div class="container py-3 py-md-4" style="max-width:820px">

    <x-page-header
        title="Статусы заявок"
        mobileTitle="Статусы заявок"
        backUrl="{{ route('admin.settings.index') }}"
        backLabel="К настройкам">
        <x-slot name="actions">
            <form method="POST" action="{{ route('admin.order-states.sync') }}" data-submit-guard>
                @csrf
                <button type="submit" class="btn btn-outline-primary">
                    <i class="bi bi-arrow-repeat"></i> Обновить из МойСклад
                </button>
            </form>
        </x-slot>
        <x-slot name="mobileActions">
            <form method="POST" action="{{ route('admin.order-states.sync') }}" data-submit-guard>
                @csrf
                <button type="submit" class="btn btn-outline-primary btn-sm" title="Обновить из МойСклад">
                    <i class="bi bi-arrow-repeat"></i>
                </button>
            </form>
        </x-slot>
    </x-page-header>

    @include('partials.alerts')

    <div class="info-block">
        <div class="info-block-header small fw-semibold">Как это работает</div>
        <div class="info-block-body small text-muted">
            Список, имена и цвета статусов приходят из МойСклад — нажмите «Обновить из МойСклад»,
            если там завели или переименовали статус.
            Отмеченные статусы подгружаются при синхронизации заявок, снятые — нет.
            <strong>Сняв галочку, вы убираете заявки в этом статусе из программы</strong>
            при следующей синхронизации.
        </div>
    </div>

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
                    @foreach($states as $state)
                        <label class="d-flex align-items-center gap-2 px-2 py-2 m-0"
                               style="border-bottom:1px solid #f1f3f5; cursor:pointer">
                            <input type="checkbox" class="form-check-input mt-0 flex-shrink-0"
                                   name="enabled[]" value="{{ $state->id }}"
                                   {{ $state->is_enabled ? 'checked' : '' }}>

                            <span class="badge flex-shrink-0"
                                  style="background-color: {{ $state->hex_color }}; color: {{ \App\Support\BadgeColor::textFor($state->hex_color) }}">
                                {{ $state->name }}
                            </span>

                            <span class="flex-grow-1"></span>

                            @if($state->archived)
                                <span class="badge bg-danger-subtle text-danger-emphasis"
                                      title="Статуса больше нет в МойСклад">нет в МойСклад</span>
                            @endif

                            @if($state->state_type && $state->state_type !== 'Regular')
                                <span class="text-muted" style="font-size:.72rem">{{ $state->state_type }}</span>
                            @endif
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="p-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> Сохранить
                </button>
                <a href="{{ route('admin.settings.index') }}" class="btn btn-outline-secondary">Отмена</a>
            </div>
        </form>
    @endif

</div>
@endsection
