@extends('layouts.app')

@section('content')
<div class="container py-3">

    <x-page-header
        title="Контрагенты"
        mobileTitle="Контрагенты"
        backUrl="{{ route('home') }}"
        backLabel="Главная">
        <x-slot name="actions">
            <form method="POST" action="{{ route('counterparties.sync') }}">
                @csrf
                <button type="submit" class="btn btn-primary btn-lg px-4">
                    <i class="bi bi-arrow-repeat"></i> Синхронизировать
                </button>
            </form>
        </x-slot>
        <x-slot name="mobileActions">
            <form method="POST" action="{{ route('counterparties.sync') }}">
                @csrf
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-arrow-repeat"></i>
                </button>
            </form>
        </x-slot>
    </x-page-header>

    @include('partials.alerts')

    {{-- Мобильная кнопка синхронизации --}}
    <div class="d-md-none mb-2">
        <form method="POST" action="{{ route('counterparties.sync') }}">
            @csrf
            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-arrow-repeat"></i> Синхронизировать с МойСклад
            </button>
        </form>
    </div>

    {{-- Десктоп --}}
    <div class="d-none d-md-block">
        <div class="card shadow-sm">
            <div class="card-body p-0">
                @if($counterparties->isEmpty())
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-people fs-1 d-block mb-2"></i>
                        Контрагенты не загружены. Нажмите «Синхронизировать».
                    </div>
                @else
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">#</th>
                                <th>Наименование</th>
                                <th>МойСклад ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($counterparties as $i => $cp)
                                <tr>
                                    <td class="ps-3 text-muted small">{{ $i + 1 }}</td>
                                    <td class="fw-semibold">{{ $cp->name }}</td>
                                    <td class="text-muted small font-monospace">{{ $cp->moysklad_id }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
            @if($counterparties->isNotEmpty())
                <div class="card-footer text-muted small">
                    Всего: {{ $counterparties->count() }}
                </div>
            @endif
        </div>
    </div>

    {{-- Мобильный --}}
    <div class="d-md-none">
        @if($counterparties->isEmpty())
            <div class="text-center py-5 text-muted">
                <i class="bi bi-people fs-1 d-block mb-2"></i>
                Контрагенты не загружены. Нажмите «Синхронизировать».
            </div>
        @else
            @foreach($counterparties as $cp)
                <div class="card mb-2 shadow-sm">
                    <div class="card-body py-2 px-3">
                        <div class="fw-semibold">{{ $cp->name }}</div>
                        <div class="text-muted small font-monospace">{{ $cp->moysklad_id }}</div>
                    </div>
                </div>
            @endforeach
            <div class="text-muted small text-center mt-2">Всего: {{ $counterparties->count() }}</div>
        @endif
    </div>

</div>
@endsection
