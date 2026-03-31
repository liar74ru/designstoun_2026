@extends('layouts.app')

@section('title', 'Партии сырья')

@section('content')
<div class="container py-3">

    <x-page-header
        title="📦 Партии сырья"
        mobileTitle="Партии сырья"
        :hide-mobile="true">
        <x-slot name="actions">
            <a href="{{ route('raw-batches.create') }}" class="btn btn-success btn-lg px-4">
                <i class="bi bi-plus-circle"></i> Новая партия
            </a>
        </x-slot>
    </x-page-header>

    {{-- Мобильная кнопка --}}
    <div class="d-md-none mb-2">
        <a href="{{ route('raw-batches.create') }}" class="btn btn-success w-100">
            <i class="bi bi-plus-circle"></i> Новая партия
        </a>
    </div>

    @include('partials.alerts')

    {{-- Фильтры --}}
    <form method="GET" id="filterForm" class="card shadow-sm mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-2"
             style="cursor:pointer" id="filter-toggle" role="button">
            <span class="fw-semibold text-muted small">
                <i class="bi bi-funnel me-1"></i> Фильтры
                <span id="filter-active-badge" class="ms-1"></span>
            </span>
            <i class="bi bi-chevron-down" id="filter-chevron"></i>
        </div>
        <div id="filter-collapse" style="display:none">
            <div class="card-body pb-2">
                <div class="row g-2">
                    <div class="col-6 col-md-3">
                        <label class="form-label small mb-1">Статус</label>
                        <select name="filter[status]" class="form-select" style="font-size:.8rem;padding:.18rem .35rem;border-radius:.4rem">
                            <option value="">Все</option>
                            @foreach($statuses as $value => $label)
                                <option value="{{ $value }}" {{ request('filter.status') == $value ? 'selected' : '' }}>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small mb-1">Пильщик</label>
                        <select name="filter[current_worker_id]" class="form-select" style="font-size:.8rem;padding:.18rem .35rem;border-radius:.4rem">
                            <option value="">Все</option>
                            @foreach($workers as $worker)
                                <option value="{{ $worker->id }}" {{ request('filter.current_worker_id') == $worker->id ? 'selected' : '' }}>
                                    {{ $worker->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small mb-1">Сырьё</label>
                        <select name="filter[product_id]" class="form-select" style="font-size:.8rem;padding:.18rem .35rem;border-radius:.4rem">
                            <option value="">Все</option>
                            @foreach($products as $product)
                                <option value="{{ $product->id }}" {{ request('filter.product_id') == $product->id ? 'selected' : '' }}>
                                    {{ $product->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small mb-1">Поиск по номеру</label>
                        <input type="text" name="filter[batch_number]" class="form-control"
                               style="font-size:.8rem;padding:.18rem .35rem;border-radius:.4rem"
                               value="{{ request('filter.batch_number') }}">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label small mb-1">Группа товаров</label>
                        <x-group-filter
                            :groups="$groupsTree"
                            :activeGroupId="request('filter.group_id')"
                            formId="filterForm"
                            inputName="filter[group_id]"
                        />
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-funnel"></i> Применить
                        </button>
                        <a href="{{ route('raw-batches.index') }}" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-x-circle"></i> Сбросить
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </form>

    @if($batches->count() > 0)

        {{-- Десктоп --}}
        <div class="d-none d-md-block card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>№ партии</th>
                            <th>Продукт</th>
                            <th>Остаток</th>
                            <th>Статус</th>
                            <th>Текущий склад</th>
                            <th>Пильщик</th>
                            <th>Дата создания</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($batches as $batch)
                        <tr>
                            <td>
                                <a href="{{ route('raw-batches.show', $batch->id) }}">
                                    {{ $batch->batch_number ?? '—' }}
                                </a>
                            </td>
                            <td>
                                <a href="{{ route('products.show', $batch->product->moysklad_id) }}">
                                    {{ $batch->product->name }}
                                </a>
                            </td>
                            <td>
                                <span class="badge {{ $batch->remaining_quantity > 0 ? 'bg-primary' : 'bg-secondary' }}">
                                    {{ number_format($batch->remaining_quantity, 3) }}
                                </span>
                            </td>
                            <td>
                                <span class="badge {{ $batch->statusBadgeClass() }}">
                                    {{ $batch->statusLabel() }}
                                </span>
                            </td>
                            <td>{{ $batch->currentStore->name ?? '—' }}</td>
                            <td>{{ $batch->currentWorker->name ?? '—' }}</td>
                            <td>{{ $batch->created_at->format('d.m.Y H:i') }}</td>
                            <td>
                                <div class="d-flex gap-1 flex-wrap">
                                    <a href="{{ route('raw-batches.show', $batch) }}" class="btn btn-sm btn-outline-info" title="Просмотр">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    @if($batch->canBeEditedOrDeleted())
                                        <a href="{{ route('raw-batches.edit', $batch) }}" class="btn btn-sm btn-outline-secondary" title="Редактировать">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <form method="POST" action="{{ route('raw-batches.destroy-new', $batch) }}"
                                              class="d-inline"
                                              onsubmit="return confirm('Удалить партию #{{ $batch->id }}? Это действие необратимо.')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Удалить">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    @endif
                                    @if($batch->status !== 'archived')
                                        <a href="{{ route('raw-batches.adjust.form', $batch) }}" class="btn btn-sm btn-outline-success" title="Скорректировать количество">
                                            <i class="bi bi-plus-slash-minus"></i>
                                        </a>
                                    @endif
                                    <a href="{{ route('raw-batches.copy', $batch) }}" class="btn btn-sm btn-outline-primary" title="Создать копию">
                                        <i class="bi bi-copy"></i>
                                    </a>
                                    @if($batch->isWorkable())
                                        <a href="{{ route('raw-batches.transfer.form', $batch) }}" class="btn btn-sm btn-outline-warning" title="Передать пильщику">
                                            <i class="bi bi-arrow-left-right"></i>
                                        </a>
                                        <a href="{{ route('raw-batches.return.form', $batch) }}" class="btn btn-sm btn-outline-secondary" title="Вернуть на склад">
                                            <i class="bi bi-arrow-return-left"></i>
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Мобильный --}}
        <div class="d-md-none">
            @foreach($batches as $batch)
                @php
                    $skuColor = \App\Models\Product::getColorBySku($batch->product->sku ?? null);
                    $skuBg    = $skuColor === '#FFFFFF' ? '' : 'background:' . $skuColor . '18;';
                @endphp
                <div class="info-block mb-2" style="border-left:4px solid {{ $skuColor }};border-right:4px solid {{ $skuColor }};{{ $skuBg }}">
                    <div class="info-block-header d-flex justify-content-between align-items-center">
                        <a href="{{ route('raw-batches.show', $batch->id) }}" class="fw-semibold small text-decoration-none text-dark">
                            {{ $batch->batch_number ?? '—' }}
                        </a>
                        <span class="badge {{ $batch->statusBadgeClass() }}">
                            {{ $batch->statusLabel() }}
                        </span>
                    </div>
                    @php $fmt = fn($v) => rtrim(rtrim(number_format($v, 2), '0'), '.'); @endphp
                    <div class="info-block-body d-flex gap-2 align-items-stretch">
                        {{-- Левая часть: информация --}}
                        <div class="flex-grow-1 min-w-0 d-flex flex-column justify-content-between">
                            <div>
                                <div class="fw-semibold mb-1">{{ $batch->product->name ?? '—' }}</div>
                                <div class="small text-muted mb-1">
                                    <i class="bi bi-box-arrow-right me-1"></i>{{ $batch->latestMovement?->fromStore?->name ?? '—' }}
                                </div>
                                <div class="small text-muted mb-1">
                                    <i class="bi bi-box-arrow-in-right me-1"></i>{{ $batch->latestMovement?->toStore?->name ?? '—' }}
                                </div>
                                <div class="small mb-1">
                                    <i class="bi bi-person me-1 text-muted"></i>
                                    <span class="fw-semibold">{{ $batch->currentWorker?->name ?? '—' }}</span>
                                </div>
                                <div class="small text-muted mb-1">
                                    <i class="bi bi-calendar me-1"></i>{{ $batch->created_at->format('d.m.Y') }}
                                </div>
                            </div>
                            <div class="d-flex flex-column gap-1 mt-1">
                                <div>
                                    <span class="badge rounded-pill bg-primary">
                                        перемещ.: {{ $batch->latestMovement?->quantity ? $fmt($batch->latestMovement->quantity).' м³' : '—' }}
                                    </span>
                                </div>
                                <div>
                                    <span class="badge rounded-pill"
                                          style="{{ $batch->remaining_quantity > 0 ? 'background:#d1e7dd;color:#0a3622' : 'background:#6c757d;color:#fff' }}">
                                        остаток: {{ $fmt($batch->remaining_quantity) }} м³
                                    </span>
                                </div>
                            </div>
                        </div>

                        {{-- Правая часть: кнопки в столбик --}}
                        <div class="d-flex flex-column gap-1 flex-shrink-0">
                            <a href="{{ route('raw-batches.show', $batch) }}" class="btn btn-sm btn-outline-info" style="min-width:90px">
                                <i class="bi bi-eye"></i> Открыть
                            </a>
                            @if($batch->canBeEditedOrDeleted())
                                <a href="{{ route('raw-batches.edit', $batch) }}" class="btn btn-sm btn-outline-secondary" style="min-width:90px">
                                    <i class="bi bi-pencil"></i> Изменить
                                </a>
                            @endif
                            @if($batch->status !== 'archived')
                                <a href="{{ route('raw-batches.adjust.form', $batch) }}" class="btn btn-sm btn-outline-success" style="min-width:90px">
                                    <i class="bi bi-plus-slash-minus"></i> Остаток
                                </a>
                            @endif
                            <a href="{{ route('raw-batches.copy', $batch) }}" class="btn btn-sm btn-outline-primary" style="min-width:90px">
                                <i class="bi bi-copy"></i> Копия
                            </a>
                            @if($batch->isWorkable())
                                <a href="{{ route('raw-batches.transfer.form', $batch) }}" class="btn btn-sm btn-outline-warning" style="min-width:90px">
                                    <i class="bi bi-arrow-left-right"></i> Передать
                                </a>
                                <a href="{{ route('raw-batches.return.form', $batch) }}" class="btn btn-sm btn-outline-secondary" style="min-width:90px">
                                    <i class="bi bi-arrow-return-left"></i> Вернуть
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="d-flex justify-content-center mt-3">
            {{ $batches->withQueryString()->links() }}
        </div>

    @else
        <div class="text-center py-5">
            <i class="bi bi-inbox display-1 text-muted"></i>
            <h3 class="text-muted mt-3">Партии не найдены</h3>
            <p class="mb-4">Создайте первую партию сырья</p>
            <a href="{{ route('raw-batches.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Новая партия
            </a>
        </div>
    @endif

</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    (function () {
        const STORAGE_KEY = 'filter_collapsed_' + window.location.pathname.replace(/\//g, '_');
        const collapse = document.getElementById('filter-collapse');
        const chevron  = document.getElementById('filter-chevron');
        const toggle   = document.getElementById('filter-toggle');
        const badge    = document.getElementById('filter-active-badge');

        const params = new URLSearchParams(window.location.search);
        const activeFilters = [
            'filter[status]', 'filter[current_worker_id]',
            'filter[product_id]', 'filter[batch_number]', 'filter[group_id]'
        ].filter(k => params.get(k) && params.get(k) !== '').length;

        if (badge && activeFilters > 0) {
            badge.innerHTML = `<span class="badge bg-primary rounded-pill">${activeFilters}</span>`;
        }

        const userOpened   = localStorage.getItem(STORAGE_KEY) === 'open';
        const shouldExpand = activeFilters > 0 || userOpened;

        function applyState(expanded, animate) {
            if (expanded) {
                collapse.style.display = '';
                if (animate) { collapse.style.opacity = '0'; setTimeout(() => collapse.style.opacity = '', 10); }
                chevron.className = 'bi bi-chevron-up';
            } else {
                if (animate) {
                    collapse.style.opacity = '0';
                    setTimeout(() => { collapse.style.display = 'none'; collapse.style.opacity = ''; }, 150);
                } else {
                    collapse.style.display = 'none';
                }
                chevron.className = 'bi bi-chevron-down';
            }
        }

        applyState(shouldExpand, false);
        toggle.addEventListener('click', function () {
            const isHidden = collapse.style.display === 'none';
            applyState(isHidden, true);
            localStorage.setItem(STORAGE_KEY, isHidden ? 'open' : 'closed');
        });
    })();
});
</script>
@endpush
@endsection
