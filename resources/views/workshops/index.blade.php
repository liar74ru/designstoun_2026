@extends('layouts.app')
@section('title', 'Цех')

@section('content')
<div class="container py-3 py-md-4">

    <x-page-header title="🏭 Цех" :hide-mobile="true">
        <x-slot:actions>
            <a href="{{ route('workshops.create') }}" class="btn btn-success btn-lg px-4">
                <i class="bi bi-plus-circle"></i> Новая операция
            </a>
        </x-slot:actions>
    </x-page-header>

    @include('partials.alerts')

    <a href="{{ route('workshops.create') }}" class="btn btn-success d-md-none w-100 mb-3">
        <i class="bi bi-plus-circle"></i> Новая операция
    </a>

    {{-- ═══════════════════════ ФИЛЬТРЫ ═══════════════════════ --}}
    <form method="GET" id="filter-form" class="card shadow-sm mb-2 mb-md-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-2"
             style="cursor:pointer" id="filter-toggle" role="button">
            <span class="fw-semibold text-muted small">
                <i class="bi bi-funnel me-1"></i> Фильтры
            </span>
            <i class="bi bi-chevron-down" id="filter-chevron"></i>
        </div>
        <div id="filter-collapse" style="display:none">
            <div class="card-body pb-2">
                <div class="row g-2 mb-3">
                    <div class="col-6 col-sm-auto">
                        <label class="form-label small text-muted mb-1">С</label>
                        <input type="date" name="date_from" class="form-control" style="border-radius:.4rem" value="{{ request('date_from') }}">
                    </div>
                    <div class="col-6 col-sm-auto">
                        <label class="form-label small text-muted mb-1">По</label>
                        <input type="date" name="date_to" class="form-control" style="border-radius:.4rem" value="{{ request('date_to') }}">
                    </div>
                </div>

                <div class="row g-2">
                    <div class="col-12 col-sm-6 col-lg-4">
                        <label class="form-label small text-muted mb-1">Работник</label>
                        <select name="filter[packer_id]" class="form-select" style="border-radius:.4rem">
                            <option value="">Все работники</option>
                            @foreach($filterPackers as $w)
                                <option value="{{ $w->id }}" {{ request('filter.packer_id') == $w->id ? 'selected' : '' }}>{{ $w->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-12 col-sm-6 col-lg-4">
                        <label class="form-label small text-muted mb-1">Тип упаковки</label>
                        <select name="filter[package_product_id]" class="form-select" style="border-radius:.4rem">
                            <option value="">Все</option>
                            @foreach($filterPackageProducts as $p)
                                <option value="{{ $p->id }}" {{ request('filter.package_product_id') == $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-12 col-sm-6 col-lg-4">
                        <label class="form-label small text-muted mb-1">Статус</label>
                        <div class="d-flex flex-wrap gap-2 mt-1 px-2 py-2 rounded" style="background:#f8f9fa;border:1px solid #e9ecef">
                            @php $statusDefaults = ['active','error']; @endphp
                            @foreach(['active' => 'Активна', 'completed' => 'Закрыта', 'error' => 'Ошибка'] as $val => $lbl)
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" name="filter[status][]" value="{{ $val }}" id="status_{{ $val }}"
                                        {{ in_array($val, (array) request('filter.status', $statusDefaults)) ? 'checked' : '' }}>
                                    <label class="form-check-label small" for="status_{{ $val }}">{{ $lbl }}</label>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="col-12 col-sm-6 col-lg-4">
                        <label class="form-label small text-muted mb-1">Синхронизация МойСклад</label>
                        <div class="d-flex flex-wrap gap-2 mt-1 px-2 py-2 rounded" style="background:#f0f7ff;border:1px solid #cfe2ff">
                            @foreach(['synced' => 'Синхр.', 'not_synced' => 'Не синхр.'] as $val => $lbl)
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" name="filter[sync_status][]" value="{{ $val }}" id="sync_{{ $val }}"
                                        {{ in_array($val, (array) request('filter.sync_status', [])) ? 'checked' : '' }}>
                                    <label class="form-check-label small" for="sync_{{ $val }}">{{ $lbl }}</label>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    @if($filterDepartments->isNotEmpty())
                        @php $selectedDepartments = (array) request('filter.department_id', $departmentDefaults); @endphp
                        <div class="col-12 col-sm-6 col-lg-4">
                            <label class="form-label small text-muted mb-1">Отдел</label>
                            <div class="d-flex flex-wrap gap-2 mt-1 px-2 py-2 rounded" style="background:#f5f0ff;border:1px solid #e0d4ff">
                                @foreach($filterDepartments as $dept)
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="checkbox"
                                               name="filter[department_id][]" value="{{ $dept->id }}"
                                               id="dept_{{ $dept->id }}"
                                            {{ in_array($dept->id, $selectedDepartments) ? 'checked' : '' }}>
                                        <label class="form-check-label small" for="dept_{{ $dept->id }}">{{ $dept->name }}</label>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                <div class="d-flex gap-2 mt-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-funnel"></i> Применить
                    </button>
                    <a href="{{ url()->current() }}" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-circle"></i> Сбросить
                    </a>
                </div>
            </div>
        </div>
    </form>

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
                                    @if($workshop->status === 'active')
                                        <a href="{{ route('workshops.edit', $workshop) }}" class="btn btn-sm btn-success" style="min-width:110px">
                                            <i class="bi bi-pencil"></i> Изменить
                                        </a>
                                        <form method="POST" action="{{ route('workshops.mark-completed', $workshop) }}" onsubmit="return confirm('Закрыть операцию?')">
                                            @csrf @method('PATCH')
                                            <button type="submit" class="btn btn-sm btn-warning w-100" style="min-width:110px">
                                                <i class="bi bi-check2-circle"></i> Закрыть
                                            </button>
                                        </form>
                                    @endif
                                    <form action="{{ route('workshops.copy', $workshop) }}" method="POST">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-info w-100" style="min-width:110px">
                                            <i class="bi bi-copy"></i> Копия
                                        </button>
                                    </form>
                                    <a href="{{ route('workshops.show', $workshop) }}" class="btn btn-sm btn-outline-secondary" style="min-width:110px">
                                        <i class="bi bi-eye"></i> Просмотр
                                    </a>
                                    @if($workshop->status === 'active')
                                        <form action="{{ route('workshops.destroy', $workshop) }}" method="POST" onsubmit="return confirm('Удалить операцию?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger w-100" style="min-width:110px">
                                                <i class="bi bi-trash"></i> Удалить
                                            </button>
                                        </form>
                                    @else
                                        <form action="{{ route('workshops.reset-status', $workshop) }}" method="POST" onsubmit="return confirm('Сбросить статус?')">
                                            @csrf @method('PATCH')
                                            <button type="submit" class="btn btn-sm btn-outline-warning w-100" style="min-width:110px">
                                                <i class="bi bi-arrow-counterclockwise"></i> Сбросить
                                            </button>
                                        </form>
                                    @endif
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

@push('scripts')
<script>
(function () {
    const collapse = document.getElementById('filter-collapse');
    const chevron  = document.getElementById('filter-chevron');
    const toggle   = document.getElementById('filter-toggle');

    const params = new URLSearchParams(window.location.search);
    const hasActive = params.get('filter[packer_id]') || params.get('filter[package_product_id]')
        || params.getAll('filter[status][]').length > 0
        || params.getAll('filter[sync_status][]').length > 0
        || params.getAll('filter[department_id][]').length > 0
        || params.get('date_from') || params.get('date_to');

    function applyState(expanded) {
        collapse.style.display = expanded ? '' : 'none';
        chevron.className = expanded ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
    }

    applyState(!!hasActive);
    toggle.addEventListener('click', () => applyState(collapse.style.display === 'none'));
})();
</script>
@endpush
