@extends('layouts.app')

@section('title', 'Отделы')

@section('content')
<div class="container py-3" style="max-width:720px">

    <x-page-header
        title="Отделы"
        mobileTitle="Отделы"
        :backUrl="route('admin.settings.index')"
        backLabel="К настройкам">
        <x-slot name="actions">
            @include('partials.help-button', ['page' => 'settings'])
            <a href="{{ route('admin.departments.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Добавить отдел
            </a>
        </x-slot>
        <x-slot name="mobileActions">
            @include('partials.help-button', ['page' => 'settings', 'class' => 'btn-outline-secondary btn-sm'])
            <a href="{{ route('admin.departments.create') }}" class="btn btn-primary btn-sm" title="Добавить отдел">
                <i class="bi bi-plus-lg"></i>
            </a>
        </x-slot>
    </x-page-header>

    @include('partials.alerts')

    @php
        // Склады по умолчанию: заданы ли — подробности и правка в карточке отдела
        $storeSlots = [
            'default_raw_store_id'        => 'Склад сырья',
            'default_product_store_id'    => 'Склад продукции',
            'default_production_store_id' => 'Склад производства',
        ];
    @endphp

    <div class="card shadow-sm">
        <div class="list-group list-group-flush">
            @forelse($departments as $dept)
                <a href="{{ route('admin.departments.show', $dept) }}"
                   class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-2 {{ $dept->is_active ? '' : 'bg-light' }}">
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold {{ $dept->is_active ? '' : 'text-muted' }}">
                            {{ $dept->name }}
                            @if($dept->code)
                                <span class="text-muted small fw-normal">· {{ $dept->code }}</span>
                            @endif
                            @unless($dept->is_active)
                                <span class="badge text-bg-secondary ms-1">неактивен</span>
                            @endunless
                        </div>
                        <div class="text-muted small">Сотрудников: {{ $dept->all_workers_count }}</div>
                    </div>

                    <div class="d-flex gap-1 small text-nowrap" title="Склады по умолчанию">
                        <span class="text-muted d-none d-md-inline me-1">Склады</span>
                        @foreach($storeSlots as $field => $label)
                            @if($dept->{$field})
                                <i class="bi bi-check-circle-fill text-success" title="{{ $label }}: задан"></i>
                            @else
                                <i class="bi bi-dash-circle text-muted" title="{{ $label }}: не задан"></i>
                            @endif
                        @endforeach
                    </div>

                    <i class="bi bi-chevron-right text-muted"></i>
                </a>
            @empty
                <div class="list-group-item text-muted small">Отделов пока нет.</div>
            @endforelse
        </div>
    </div>

</div>
@endsection
