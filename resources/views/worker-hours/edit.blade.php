@extends('layouts.app')

@section('title', 'Часы ремонтных работ — ' . $entry->worker->name)

@section('content')
    <div class="container py-3 py-md-4" style="max-width:640px">
        <x-page-header title="Ремонтные работы — {{ $entry->worker->name }}" mobileTitle="Ремонтные работы" :backUrl="$backUrl" />

        @if($errors->any())
            <div class="alert alert-danger py-2 m-2">{{ $errors->first() }}</div>
        @endif

        <div class="card shadow-sm">
            <form method="POST" action="{{ route('worker-hours.update', $entry) }}" data-submit-guard class="card-body p-0">
                @csrf
                @method('PUT')

                <div class="info-block m-2">
                    <div class="info-block-header">Дата работы</div>
                    <div class="info-block-body">
                        {{-- Админ правит любую прошедшую дату; остальным — только невыплаченная неделя --}}
                        <input type="date" name="work_date"
                               class="form-control @error('work_date') is-invalid @enderror"
                               value="{{ old('work_date', $entry->work_date->toDateString()) }}"
                               @unless(auth()->user()->isAdmin()) min="{{ $unpaidFrom->toDateString() }}" @endunless
                               max="{{ now()->toDateString() }}" required>
                    </div>
                </div>

                <div class="info-block m-2">
                    <div class="info-block-header">Часы</div>
                    <div class="info-block-body">
                        <input type="number" name="hours" inputmode="decimal"
                               class="form-control @error('hours') is-invalid @enderror"
                               value="{{ old('hours', rtrim(rtrim(number_format($entry->hours, 2, '.', ''), '0'), '.')) }}"
                               min="0.25" max="24" step="0.25" required>
                        {{-- Деньги — только самому работнику и админу, как на дашборде --}}
                        @if(auth()->user()->isAdmin() || auth()->user()->worker_id === $entry->worker_id)
                            <div class="form-text">
                                Ставка записи — {{ number_format($entry->hourly_rate, 0, ',', ' ') }} ₽/ч: она не меняется вместе со ставкой отдела.
                            </div>
                        @endif
                    </div>
                </div>

                <div class="info-block m-2">
                    <div class="info-block-header">Что делали <span class="text-muted fw-normal">(необязательно)</span></div>
                    <div class="info-block-body">
                        <input type="text" name="comment" maxlength="255"
                               class="form-control @error('comment') is-invalid @enderror"
                               value="{{ old('comment', $entry->comment) }}">
                    </div>
                </div>

                <div class="p-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Сохранить</button>
                    <a href="{{ $backUrl }}" class="btn btn-outline-secondary" data-back>Отмена</a>
                </div>
            </form>
        </div>
    </div>
@endsection
