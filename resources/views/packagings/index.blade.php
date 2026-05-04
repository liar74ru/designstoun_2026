@extends('layouts.app')
@section('title', 'Упаковка')

@section('content')
<div class="container py-3 py-md-4">

    <x-page-header title="📦 Упаковка" :hide-mobile="true">
        <x-slot:actions>
            <a href="{{ route('packagings.create') }}" class="btn btn-success btn-lg px-4">
                <i class="bi bi-plus-circle"></i> Новая упаковка
            </a>
        </x-slot:actions>
    </x-page-header>

    @include('partials.alerts')

    <a href="{{ route('packagings.create') }}" class="btn btn-success d-md-none w-100 mb-3">
        <i class="bi bi-plus-circle"></i> Новая упаковка
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
                        <label class="form-label small text-muted mb-1">Упаковщик</label>
                        <select name="filter[packer_id]" class="form-select" style="border-radius:.4rem">
                            <option value="">Все упаковщики</option>
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

    {{-- ═══════════════════════ ТАБЛИЦА ═══════════════════════ --}}
    @if($packagings->count() > 0)
        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex justify-content-end align-items-center py-2">
                <span class="text-muted small">Найдено: {{ $packagings->total() }}</span>
            </div>

            {{-- ─── ДЕСКТОП ─── --}}
            <div class="d-none d-md-block">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Дата</th>
                                <th>Упаковано</th>
                                <th>Итого</th>
                                <th>Тара</th>
                                <th>Шт</th>
                                <th>Упаковщик</th>
                                <th>Отдел</th>
                                <th>Склад</th>
                                <th>Статус</th>
                                <th class="text-end">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($packagings as $packaging)
                            <tr class="{{ $packaging->status === 'completed' ? 'table-warning' : ($packaging->status === 'error' ? 'table-danger' : '') }}">
                                <td>{{ $packaging->id }}</td>
                                <td class="text-nowrap">{{ $packaging->created_at->format('d.m.Y H:i') }}</td>
                                <td>
                                    @foreach($packaging->items as $item)
                                        <div class="{{ !$loop->last ? 'mb-1 pb-1 border-bottom' : '' }}">
                                            <strong>{{ $item->product->name }}</strong><br>
                                            <small class="text-muted">{{ $item->product->sku }}</small>
                                            <span class="badge bg-info ms-1">{{ number_format($item->quantity, 3) }}</span>
                                        </div>
                                    @endforeach
                                </td>
                                <td><span class="badge bg-primary">{{ number_format($packaging->total_quantity, 3) }}</span></td>
                                <td>
                                    @if($packaging->packageProduct)
                                        {{ $packaging->packageProduct->name }}<br>
                                        <small class="text-muted">{{ $packaging->packageProduct->sku }}</small>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td><span class="badge bg-warning text-dark">{{ number_format($packaging->package_quantity, 0) }}</span></td>
                                <td>{{ $packaging->packer->name ?? '—' }}</td>
                                <td class="small text-muted">{{ $packaging->department?->name ?? '—' }}</td>
                                <td>{{ $packaging->store->name ?? '—' }}</td>
                                <td>
                                    @if($packaging->status === 'active')
                                        <span class="badge bg-success">Активна</span>
                                    @elseif($packaging->status === 'completed')
                                        <span class="badge bg-warning text-dark">Закрыта</span>
                                    @else
                                        <span class="badge bg-danger">Ошибка</span>
                                    @endif
                                    @if($packaging->moysklad_sync_status && !$packaging->isSynced())
                                        <br><span class="badge {{ $packaging->syncStatusBadgeClass() }} mt-1">{{ $packaging->syncStatusLabel() }}</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="d-flex gap-1 justify-content-end">
                                        @if($packaging->status === 'active')
                                            <a href="{{ route('packagings.edit', $packaging) }}" class="btn btn-sm btn-success" title="Редактировать">
                                                <i class="bi bi-plus-lg"></i>
                                            </a>
                                            <form method="POST" action="{{ route('packagings.mark-completed', $packaging) }}" class="d-inline" onsubmit="return confirm('Закрыть упаковку?')">
                                                @csrf @method('PATCH')
                                                <button type="submit" class="btn btn-sm btn-warning" title="Закрыть упаковку">
                                                    <i class="bi bi-check2-circle"></i>
                                                </button>
                                            </form>
                                        @endif
                                        <form action="{{ route('packagings.copy', $packaging) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-info" title="Копировать">
                                                <i class="bi bi-copy"></i>
                                            </button>
                                        </form>
                                        <a href="{{ route('packagings.show', $packaging) }}" class="btn btn-sm btn-outline-secondary" title="Просмотр">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        @if($packaging->status !== 'active')
                                            <form action="{{ route('packagings.reset-status', $packaging) }}" method="POST" class="d-inline" onsubmit="return confirm('Сбросить статус?')">
                                                @csrf @method('PATCH')
                                                <button type="submit" class="btn btn-sm btn-outline-warning" title="Сбросить статус">
                                                    <i class="bi bi-arrow-counterclockwise"></i>
                                                </button>
                                            </form>
                                        @endif
                                        @if($packaging->status === 'active')
                                            <form action="{{ route('packagings.destroy', $packaging) }}" method="POST" class="d-inline" onsubmit="return confirm('Удалить упаковку?')">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Удалить">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- ─── МОБИЛЬНЫЙ ─── --}}
            <div class="d-md-none" style="padding:.25rem">
                @foreach($packagings as $packaging)
                    <div class="card mb-2 shadow-sm" style="border-radius:.4rem">
                        <div class="card-body p-2">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="small text-muted">{{ $packaging->created_at->format('d.m H:i') }} · #{{ $packaging->id }}</div>
                                    <div class="fw-semibold">{{ $packaging->packer->name ?? '—' }}</div>
                                </div>
                                <div>
                                    @if($packaging->status === 'active')
                                        <span class="badge bg-success">Активна</span>
                                    @elseif($packaging->status === 'completed')
                                        <span class="badge bg-warning text-dark">Закрыта</span>
                                    @else
                                        <span class="badge bg-danger">Ошибка</span>
                                    @endif
                                </div>
                            </div>

                            @foreach($packaging->items as $item)
                                <div class="small mt-1">
                                    {{ $item->product->name }}: <strong>{{ number_format($item->quantity, 3) }}</strong>
                                </div>
                            @endforeach

                            @if($packaging->packageProduct)
                                <div class="small mt-1">
                                    Тара: {{ $packaging->packageProduct->name }} × <strong>{{ number_format($packaging->package_quantity, 0) }}</strong>
                                </div>
                            @endif

                            <div class="d-flex gap-1 mt-2">
                                @if($packaging->status === 'active')
                                    <a href="{{ route('packagings.edit', $packaging) }}" class="btn btn-sm btn-success flex-fill">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                @endif
                                <a href="{{ route('packagings.show', $packaging) }}" class="btn btn-sm btn-outline-secondary flex-fill">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Пагинация --}}
            <div class="d-flex justify-content-between align-items-center p-2 p-md-3 border-top">
                <span class="text-muted small">
                    Показано {{ $packagings->firstItem() }}–{{ $packagings->lastItem() }} из {{ $packagings->total() }}
                </span>
                {{ $packagings->links() }}
            </div>
        </div>
    @else
        <div class="text-center py-5">
            <i class="bi bi-box-seam display-1 text-muted"></i>
            <h4 class="text-muted mt-3">Упаковок не найдено</h4>
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
