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
            Статусы в колонке <strong>«Исп.»</strong> подгружаются при синхронизации заявок,
            снятые — нет. <strong>Сняв галочку, вы убираете заявки в этом статусе из программы</strong>
            при следующей синхронизации.
            <div class="mt-1">
                Колонка <strong>«Произв.»</strong> — статус, в котором идёт производство.
                Пока заявка в нём, остаток на складе зафиксирован, а всё произведённое
                попадает в колонку «Изготовлено» карточки заявки.
            </div>
            <div class="mt-1">
                Колонка <strong>«В списке»</strong> — какие статусы показывать в списке заявок
                при заходе без фильтра. Если не отмечено ничего, показываются все используемые.
            </div>
            <div class="mt-1">
                Колонка <strong>«Следить»</strong> — замечать, что в МойСклад изменили количество
                или состав позиций заявки: мастер увидит плашку «Изменена» и «было → стало».
                Снимите у статусов, где правки нормальны (проект, отгружен, завершён).
            </div>
            <div class="mt-1">
                Колонка <strong>«Изм.»</strong> — статус «Изменено». Это пауза внутри производства:
                окно производства не закрывается, а кнопка «Принято» у заявки возвращает её
                в статус, который был до него. Такой статус всегда используется.
            </div>
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
                    {{-- Независимые галочки в строке, поэтому строка не обёрнута
                         в общий <label>: он переключал бы только первую. --}}
                    <div class="d-flex align-items-center gap-2 px-2 py-1 text-muted"
                         style="border-bottom:1px solid #f1f3f5; font-size:.72rem">
                        <span class="flex-grow-1">Статус</span>
                        <span class="text-center" style="width:44px">Исп.</span>
                        <span class="text-center" style="width:56px">Произв.</span>
                        <span class="text-center" style="width:58px">В списке</span>
                        <span class="text-center" style="width:52px">Следить</span>
                        <span class="text-center" style="width:40px">Изм.</span>
                    </div>

                    <label class="d-flex align-items-center gap-2 px-2 py-1 m-0 text-muted"
                           style="border-bottom:1px solid #f1f3f5; font-size:.78rem; cursor:pointer">
                        <span class="flex-grow-1">Статуса «Изменено» нет</span>
                        <span class="d-flex justify-content-center" style="width:40px">
                            <input type="radio" class="form-check-input mt-0" name="changed_state" value=""
                                   {{ $states->contains('is_changed', true) ? '' : 'checked' }}>
                        </span>
                    </label>

                    @foreach($states as $state)
                        <div class="d-flex align-items-center gap-2 px-2 py-2"
                             style="border-bottom:1px solid #f1f3f5">
                            <span class="badge flex-shrink-0"
                                  style="background-color: {{ $state->hex_color }}; color: {{ \App\Support\BadgeColor::textFor($state->hex_color) }}">
                                {{ $state->name }}
                            </span>

                            @if($state->archived)
                                <span class="badge bg-danger-subtle text-danger-emphasis"
                                      title="Статуса больше нет в МойСклад">нет в МойСклад</span>
                            @endif

                            @if($state->state_type && $state->state_type !== 'Regular')
                                <span class="text-muted" style="font-size:.72rem">{{ $state->state_type }}</span>
                            @endif

                            <span class="flex-grow-1"></span>

                            <label class="d-flex justify-content-center m-0" style="width:44px; cursor:pointer"
                                   title="Подгружать заявки в этом статусе">
                                <input type="checkbox" class="form-check-input mt-0"
                                       name="enabled[]" value="{{ $state->id }}"
                                       {{ $state->is_enabled ? 'checked' : '' }}>
                            </label>

                            <label class="d-flex justify-content-center m-0" style="width:56px; cursor:pointer"
                                   title="В этом статусе идёт производство">
                                <input type="checkbox" class="form-check-input mt-0"
                                       name="production[]" value="{{ $state->id }}"
                                       {{ $state->is_production ? 'checked' : '' }}>
                            </label>

                            <label class="d-flex justify-content-center m-0" style="width:58px; cursor:pointer"
                                   title="Показывать в списке заявок по умолчанию">
                                <input type="checkbox" class="form-check-input mt-0"
                                       name="default_filter[]" value="{{ $state->id }}"
                                       {{ $state->is_default_filter ? 'checked' : '' }}>
                            </label>

                            <label class="d-flex justify-content-center m-0" style="width:52px; cursor:pointer"
                                   title="Замечать изменение позиций заявок в этом статусе">
                                <input type="checkbox" class="form-check-input mt-0"
                                       name="track_changes[]" value="{{ $state->id }}"
                                       {{ $state->track_changes ? 'checked' : '' }}>
                            </label>

                            <label class="d-flex justify-content-center m-0" style="width:40px; cursor:pointer"
                                   title="Это статус «Изменено»">
                                <input type="radio" class="form-check-input mt-0"
                                       name="changed_state" value="{{ $state->id }}"
                                       {{ $state->is_changed ? 'checked' : '' }}>
                            </label>
                        </div>
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
