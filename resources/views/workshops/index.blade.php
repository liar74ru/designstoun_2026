@extends('layouts.app')
@section('title', 'Цех')

@section('content')
<div class="container py-3 py-md-4">

    <x-page-header title="🏭 Цех" :hide-mobile="true">
        <x-slot:actions>
            @include('partials.help-button', ['page' => 'workshops', 'class' => 'btn-outline-secondary btn-lg'])
            <a href="{{ route('workshops.create') }}" class="btn btn-success btn-lg px-4">
                <i class="bi bi-plus-circle"></i> Новая операция
            </a>
        </x-slot:actions>
    </x-page-header>

    @include('partials.alerts')

    <div class="d-flex d-md-none gap-2 mb-3">
        <a href="{{ route('workshops.create') }}" class="btn btn-success flex-grow-1">
            <i class="bi bi-plus-circle"></i> Новая операция
        </a>
        @include('partials.help-button', ['page' => 'workshops'])
    </div>

    {{-- ═══════════════════════ ФИЛЬТРЫ ═══════════════════════ --}}
    @include('partials.filters', [
        'filterCutters'         => $filterPackers,
        'cutterParam'           => 'packer_id',
        'cutterDefaultIds'      => $cutterDefaultIds,
        'filterRawProducts'     => $filterRawProducts,
        'rawProductParam'       => 'raw_product_id',
        'filterProducts'        => $filterProducts,
        'filterPackageProducts' => $filterPackageProducts,
        'showStatus'            => 'multi',
        'statusOptions'         => ['active' => 'Активна', 'completed' => 'Закрыта', 'error' => 'Ошибка'],
        'statusDefaults'        => [],
        'showSyncStatus'        => true,
        'syncStatusOptions'     => ['synced' => 'Синхр.', 'not_synced' => 'Не синхр.'],
        'filterDepartments'     => $filterDepartments,
        'departmentDefaults'    => $departmentDefaults,
    ])

    {{-- ═══════════════════════ ПЛИТКИ ОПЕРАЦИЙ ═══════════════════════ --}}
    @if($workshops->count() > 0)
        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex justify-content-end align-items-center py-2">
                <span class="text-muted small">Найдено: {{ $workshops->total() }}</span>
            </div>

            {{-- ─── ПЛИТКИ ─── --}}
            <div class="row g-1 p-2 mx-0">
                @foreach($workshops as $workshop)
                    <div class="col-12 col-md-6">
                        @include('workshops.partials.card', ['workshop' => $workshop])
                    </div>
                @endforeach
            </div>

            {{-- Пагинация --}}
            <div class="d-flex justify-content-between align-items-center p-2 p-md-3 border-top">
                <span class="text-muted small">
                    Показано {{ $workshops->firstItem() }}–{{ $workshops->lastItem() }} из {{ $workshops->total() }}
                </span>
                {{ $workshops->links() }}
            </div>
        </div>
    @else
        <div class="text-center py-5">
            <i class="bi bi-box-seam display-1 text-muted"></i>
            <h4 class="text-muted mt-3">Операций не найдено</h4>
        </div>
    @endif
</div>
@endsection

