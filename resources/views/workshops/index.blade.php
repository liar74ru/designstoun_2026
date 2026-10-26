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
            <div class="row g-2 p-2 mx-0">
                @foreach($workshops as $workshop)
                    @php
                        $rawItems     = $workshop->items->where('role', \App\Models\WorkshopItem::ROLE_RAW);
                        $packageItems = $workshop->items->where('role', \App\Models\WorkshopItem::ROLE_PACKAGE);
                        $skuColor     = \App\Models\Product::getColorBySku($rawItems->first()?->product?->sku);
                        $skuBg        = $skuColor === '#FFFFFF' ? '' : 'background:' . $skuColor . '18;';
                    @endphp
                    <div class="col-12 col-md-6">
                        <div class="info-block h-100 mb-0" style="border-left:4px solid {{ $skuColor }};border-right:4px solid {{ $skuColor }};{{ $skuBg }}">
                            <div class="info-block-header d-flex justify-content-between align-items-center">
                                <a href="{{ route('workshops.show', $workshop) }}" class="fw-semibold small text-decoration-none text-dark">
                                    #{{ $workshop->id }} · {{ $workshop->created_at->format('d.m.Y H:i') }}
                                </a>
                                <div class="d-flex align-items-center gap-1">
                                    @if($workshop->status === 'active')
                                        <span class="badge bg-success">Активна</span>
                                    @elseif($workshop->status === 'completed')
                                        <span class="badge bg-warning text-dark">Закрыта</span>
                                    @else
                                        <span class="badge bg-danger">Ошибка</span>
                                    @endif
                                    @if($workshop->moysklad_sync_status && !$workshop->isSynced())
                                        <span class="badge {{ $workshop->syncStatusBadgeClass() }}">{{ $workshop->syncStatusLabel() }}</span>
                                    @endif
                                </div>
                            </div>
                            <div class="info-block-body d-flex gap-2 align-items-stretch">
                                {{-- Левая часть: информация --}}
                                <div class="flex-grow-1" style="min-width:0">
                                    @foreach($rawItems as $item)
                                        <div class="d-flex align-items-center gap-1 mb-1" style="font-size:.85rem">
                                            <ion-icon name="{{ \App\Models\Product::getIconBySku($item->product?->sku) }}" class="text-muted flex-shrink-0"></ion-icon>
                                            <span class="text-truncate fw-semibold">{{ $item->product->name }}</span>
                                            <span class="text-muted flex-shrink-0">{{ number_format($item->quantity, 3) }}</span>
                                        </div>
                                    @endforeach
                                    <div class="mb-1">
                                        <span class="badge bg-primary">Итого: {{ number_format($workshop->total_quantity, 3) }}</span>
                                    </div>
                                    @forelse($packageItems as $item)
                                        <div class="d-flex align-items-center gap-1 mb-1 small">
                                            <i class="bi bi-box-seam text-muted flex-shrink-0"></i>
                                            <span class="text-truncate">{{ $item->product->name }}</span>
                                            <span class="text-muted flex-shrink-0">× {{ number_format($item->quantity, 0) }}</span>
                                        </div>
                                    @empty
                                        <div class="small text-muted mb-1"><i class="bi bi-box-seam me-1"></i>—</div>
                                    @endforelse
                                    <div class="small mb-1">
                                        <i class="bi bi-person me-1 text-muted"></i><span class="fw-semibold">{{ $workshop->packer->name ?? '—' }}</span>
                                    </div>
                                    <div class="small text-muted">
                                        <i class="bi bi-building me-1"></i>{{ $workshop->department?->name ?? '—' }} · {{ $workshop->store->name ?? '—' }}
                                    </div>
                                </div>

                                {{-- Правая часть: кнопки в столбик --}}
                                <div class="d-flex flex-column gap-1 flex-shrink-0">
                                    @can('modify', $workshop)
                                    @if($workshop->status === 'active')
                                        <a href="{{ route('workshops.edit', $workshop) }}" class="btn btn-sm btn-success" style="min-width:110px">
                                            <i class="bi bi-pencil"></i> Изменить
                                        </a>
                                        <form method="POST" action="{{ route('workshops.mark-completed', $workshop) }}" onsubmit="return confirm('Закрыть операцию?')" data-submit-guard>
                                            @csrf @method('PATCH')
                                            <button type="submit" class="btn btn-sm btn-warning w-100" style="min-width:110px">
                                                <i class="bi bi-check2-circle"></i> Закрыть
                                            </button>
                                        </form>
                                    @endif
                                    @endcan
                                    <form action="{{ route('workshops.copy', $workshop) }}" method="POST" data-submit-guard>
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-info w-100" style="min-width:110px">
                                            <i class="bi bi-copy"></i> Копия
                                        </button>
                                    </form>
                                    <a href="{{ route('workshops.show', $workshop) }}" class="btn btn-sm btn-outline-secondary" style="min-width:110px">
                                        <i class="bi bi-eye"></i> Просмотр
                                    </a>
                                    @can('modify', $workshop)
                                    @if($workshop->status === 'active')
                                        <form action="{{ route('workshops.destroy', $workshop) }}" method="POST" onsubmit="return confirm('Удалить операцию?')" data-submit-guard>
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger w-100" style="min-width:110px">
                                                <i class="bi bi-trash"></i> Удалить
                                            </button>
                                        </form>
                                    @else
                                        <form action="{{ route('workshops.reset-status', $workshop) }}" method="POST" onsubmit="return confirm('Сбросить статус?')" data-submit-guard>
                                            @csrf @method('PATCH')
                                            <button type="submit" class="btn btn-sm btn-outline-warning w-100" style="min-width:110px">
                                                <i class="bi bi-arrow-counterclockwise"></i> Сбросить
                                            </button>
                                        </form>
                                    @endif
                                    @endcan
                                </div>
                            </div>
                        </div>
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

