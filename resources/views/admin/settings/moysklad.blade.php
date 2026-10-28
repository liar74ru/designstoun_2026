@extends('layouts.app')

@section('title', 'МойСклад')

@section('content')
<div class="container py-3" style="max-width:720px">

    <x-page-header
        title="МойСклад"
        mobileTitle="МойСклад"
        :backUrl="route('admin.settings.index')"
        backLabel="К настройкам">
        <x-slot name="actions">
            @include('partials.help-button', ['page' => 'settings'])
        </x-slot>
        <x-slot name="mobileActions">
            @include('partials.help-button', ['page' => 'settings', 'class' => 'btn-outline-secondary btn-sm'])
        </x-slot>
    </x-page-header>

    @include('partials.alerts')

    <form method="POST" action="{{ route('admin.settings.update') }}" data-submit-guard>
        @csrf

        <div class="card shadow-sm mb-3">
            <div class="card-header fw-semibold py-2">Статусы техопераций</div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    По ним программа переводит техоперации приёмки и цеха. Имя должно совпадать
                    с МойСклад символ в символ.
                </p>

                @forelse($settings as $i => $setting)
                    <div class="@if(! $loop->last) mb-3 @endif">
                        <label for="setting_{{ $setting->key }}" class="form-label fw-semibold mb-1">
                            {{ $setting->label ?? $setting->key }}
                        </label>
                        @if($setting->description)
                            <div class="text-muted small mb-1">{{ $setting->description }}</div>
                        @endif
                        <input type="text"
                               id="setting_{{ $setting->key }}"
                               name="settings[{{ $i }}][value]"
                               value="{{ old('settings.' . $i . '.value', $setting->value) }}"
                               class="form-control @error('settings.' . $i . '.value') is-invalid @enderror"
                               required>
                        <input type="hidden" name="settings[{{ $i }}][key]" value="{{ $setting->key }}">
                        @error('settings.' . $i . '.value')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                @empty
                    <div class="text-muted small">Настройки статусов не заведены — запустите сиды.</div>
                @endforelse
            </div>
        </div>

        @if($settings->isNotEmpty())
            <div class="d-grid d-md-flex justify-content-md-end mb-4">
                <button type="submit" class="btn btn-primary px-4">
                    <i class="bi bi-check-lg"></i> Сохранить
                </button>
            </div>
        @endif
    </form>

    <a href="{{ route('admin.order-states.index') }}" class="card shadow-sm text-decoration-none text-body">
        <div class="card-body d-flex align-items-center gap-3 py-2">
            <i class="bi bi-list-check text-primary fs-5"></i>
            <div class="flex-grow-1">
                <div class="fw-semibold small">Статусы заявок</div>
                <div class="text-muted small">Какие статусы заявок МойСклад использует программа и как</div>
            </div>
            <i class="bi bi-chevron-right text-muted"></i>
        </div>
    </a>

</div>
@endsection
