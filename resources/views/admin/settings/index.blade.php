@extends('layouts.app')

@section('title', 'Настройки системы')

@section('content')
<div class="container py-3" style="max-width:720px">

    <x-page-header title="Настройки системы" mobileTitle="Настройки" />

    @include('partials.alerts')

    <form method="POST" action="{{ route('admin.settings.update') }}">
        @csrf

        {{-- Ставка пильщика --}}
        @php $pieceRateSetting = $settings->firstWhere('key', 'PIECE_RATE'); @endphp
        @if($pieceRateSetting)
        <div class="card shadow-sm mb-3">
            <div class="card-header fw-semibold py-2">Расчёт зарплаты</div>
            <div class="card-body">
                @php $i = $settings->search(fn($s) => $s->key === 'PIECE_RATE'); @endphp
                <div class="mb-0">
                    <label for="setting_PIECE_RATE" class="form-label fw-semibold mb-1">
                        {{ $pieceRateSetting->label }}
                    </label>
                    @if($pieceRateSetting->description)
                        <div class="text-muted small mb-1">{{ $pieceRateSetting->description }}</div>
                    @endif
                    <input
                        type="number"
                        step="any"
                        id="setting_PIECE_RATE"
                        name="settings[{{ $i }}][value]"
                        value="{{ old('settings.' . $i . '.value', $pieceRateSetting->value) }}"
                        class="form-control @error('settings.' . $i . '.value') is-invalid @enderror"
                        required
                    >
                    <input type="hidden" name="settings[{{ $i }}][key]" value="PIECE_RATE">
                    @error('settings.' . $i . '.value')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </div>
        @endif

        {{-- Себестоимость производства --}}
        @php
            $costKeys = ['BLADE_WEAR', 'RECEPTION_COST', 'PACKAGING_COST', 'WASTE_REMOVAL',
                         'ELECTRICITY', 'PPE_COST', 'FORKLIFT_COST', 'MACHINE_COST', 'RENT_COST', 'OTHER_COSTS'];
            $costSettings = $settings->filter(fn($s) => in_array($s->key, $costKeys));
            $manualTotal = $costSettings->sum(fn($s) => (float) $s->value);
        @endphp
        <div class="card shadow-sm mb-3">
            <div class="card-header fw-semibold py-2 d-flex justify-content-between align-items-center">
                <span>Себестоимость производства</span>
                <span class="text-muted small fw-normal">ручные затраты: <strong>{{ number_format($manualTotal, 0, ',', ' ') }} ₽/м²</strong></span>
            </div>
            <div class="card-body">
                <div class="alert alert-info py-2 px-3 mb-3 small">
                    <i class="bi bi-info-circle"></i>
                    Зарплата пильщика рассчитывается автоматически по коэффициенту продукта и вынесена в блок «Расчёт зарплаты» выше.
                    Остальные компоненты вводятся вручную:
                </div>

                @foreach($costSettings as $i => $setting)
                <div class="mb-3">
                    <label for="setting_{{ $setting->key }}" class="form-label fw-semibold mb-1">
                        {{ $setting->label ?? $setting->key }}
                    </label>
                    @if($setting->description)
                        <div class="text-muted small mb-1">{{ $setting->description }}</div>
                    @endif
                    <input
                        type="number"
                        step="any"
                        id="setting_{{ $setting->key }}"
                        name="settings[{{ $i }}][value]"
                        value="{{ old('settings.' . $i . '.value', $setting->value) }}"
                        class="form-control @error('settings.' . $i . '.value') is-invalid @enderror"
                        required
                    >
                    <input type="hidden" name="settings[{{ $i }}][key]" value="{{ $setting->key }}">
                    @error('settings.' . $i . '.value')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                @endforeach
            </div>
        </div>

        <div class="d-grid d-md-flex justify-content-md-end mb-4">
            <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-check-lg"></i> Сохранить
            </button>
        </div>
    </form>

</div>
@endsection
