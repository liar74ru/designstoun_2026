@extends('layouts.app')
@section('title', 'Редактировать партию')

@section('content')
<div class="container py-3" style="max-width:560px">

    <x-page-header
        title="✏️ Редактировать партию #{{ $batch->batch_number ?? $batch->id }}"
        mobileTitle="Редактировать партию"
        :backUrl="$backUrl"
        backLabel="Назад">
    </x-page-header>

    @include('partials.alerts')

    <div class="alert alert-info py-2 mb-3 small">
        <i class="bi bi-info-circle me-1"></i>
        Редактирование доступно только для партий в статусе <strong>«Новая»</strong>.
        Изменения продукта и количества будут синхронизированы с МойСклад.
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white py-2">
            <span class="fw-semibold small text-muted">Данные партии</span>
        </div>
        <div class="card-body">
            <style>
                #editForm .form-control,
                #editForm .form-select { border-radius: .4rem; }
            </style>
            <form method="POST" action="{{ route('raw-batches.update', $batch) }}" id="editForm">
                @csrf
                @method('PUT')

                @if($errors->any())
                    <div class="alert alert-danger py-2 mb-3">
                        @foreach($errors->all() as $error)
                            <div class="small">{{ $error }}</div>
                        @endforeach
                    </div>
                @endif

                <div class="mb-3">
                    <label class="form-label fw-semibold text-muted">Номер партии</label>
                    <input type="text" class="form-control bg-light" value="{{ $batch->batch_number ?? '—' }}" readonly>
                </div>

                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label fw-semibold mb-0">
                            Сырьё <span class="text-danger">*</span>
                        </label>
                        <div class="form-check form-check-inline mb-0" id="allCatalogWrap">
                            <input class="form-check-input" type="checkbox" id="allCatalogCheck">
                            <label class="form-check-label small text-muted" for="allCatalogCheck">весь каталог</label>
                        </div>
                    </div>
                    <div class="product-picker-row" data-sku-prefix="01-" data-tpl-index="0">
                        <div class="flex-grow-1 position-relative">
                            <div class="input-group">
                                <input type="text"
                                       id="search_0"
                                       class="form-control product-picker-search"
                                       placeholder="Начните вводить название сырья..."
                                       autocomplete="off"
                                       data-hidden-id="pid_0"
                                       value="{{ old('product_name', $batch->product->name ?? '') }}"
                                       required>
                                <button type="button"
                                        class="btn btn-outline-secondary product-picker-tree-btn"
                                        data-modal="modal_0"
                                        data-hidden-id="pid_0"
                                        data-search-id="search_0"
                                        title="Выбрать из каталога">
                                    <i class="bi bi-diagram-3"></i>
                                </button>
                            </div>
                            <div class="product-picker-dropdown list-group shadow-sm"
                                 id="drop_0"
                                 style="display:none;position:absolute;z-index:1000;width:100%;max-height:280px;overflow-y:auto">
                            </div>
                        </div>
                        <input type="hidden"
                               id="pid_0"
                               name="product_id"
                               value="{{ old('product_id', $batch->product_id) }}"
                               required>

                        <div class="modal fade" id="modal_0" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Выбрать из каталога</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body" style="max-height:70vh;overflow-y:auto">
                                        <input type="text" class="form-control mb-3 tree-search-input"
                                               placeholder="Поиск по каталогу...">
                                        <div class="product-tree-container"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    @error('product_id')
                        <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="quantity" class="form-label fw-semibold">
                        Количество (м³) <span class="text-danger">*</span>
                    </label>
                    <input type="number" name="quantity" id="quantity"
                           class="form-control @error('quantity') is-invalid @enderror"
                           value="{{ old('quantity', number_format($batch->initial_quantity, 3, '.', '')) }}"
                           step="0.001" min="0.001" required>
                    @error('quantity')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <div class="form-text text-muted">
                        Текущий остаток: <strong>{{ rtrim(rtrim(number_format($batch->remaining_quantity, 2), '0'), '.') }} м³</strong>
                        — будет заменён новым значением.
                    </div>
                </div>

                <x-admin-date-field
                    hint="Изменение даты синхронизируется с МойСклад."
                    value="{{ old('manual_created_at', $batch->created_at->format('Y-m-d\TH:i')) }}" />

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i> Сохранить
                    </button>
                    <a href="{{ $backUrl }}" class="btn btn-outline-secondary text-nowrap">Отмена</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Блок удаления --}}
    <div class="card shadow-sm border-danger mt-3">
        <div class="card-body py-3">
            <h6 class="text-danger mb-1"><i class="bi bi-trash me-1"></i>Удалить партию</h6>
            <p class="text-muted small mb-3">
                Партия будет безвозвратно удалена. Перемещение в МойСклад также будет удалено.
                Сырьё вернётся на склад-источник.
            </p>
            <form method="POST" action="{{ route('raw-batches.destroy-new', $batch) }}"
                  onsubmit="return confirm('Удалить партию #{{ $batch->id }}? Это действие необратимо.')">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-trash me-1"></i> Удалить партию
                </button>
            </form>
        </div>
    </div>

</div>
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const row             = document.querySelector('.product-picker-row');
    const allCatalogCheck = document.getElementById('allCatalogCheck');
    const RAW_SKU_PREFIX  = '01-';

    if (row && window.ProductPicker) {
        window.ProductPicker.initRow(row);
    }

    allCatalogCheck?.addEventListener('change', function () {
        if (this.checked) {
            delete row.dataset.skuPrefix;
        } else {
            row.dataset.skuPrefix = RAW_SKU_PREFIX;
        }
    });
});
</script>
@vite(['resources/js/product-picker.js'])
@endpush
@endsection
