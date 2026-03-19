@extends('layouts.app')

@section('title', 'Приёмка #' . $stoneReception->id)

@section('content')
    <div class="container py-4">

        {{-- Заголовок --}}
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2 mb-0">🪨 Приёмка #{{ $stoneReception->id }}</h1>
            <a href="{{ route('stone-receptions.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> К списку
            </a>
        </div>

        {{-- Flash-сообщения --}}
        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill"></i> {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill"></i> {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        <div class="row">

            {{-- Левая колонка: основная информация --}}
            <div class="col-md-6">

                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">Основная информация</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm mb-0">
                            <tr>
                                <th style="width: 160px;">Статус:</th>
                                <td>
                                    @php
                                        $statusMap = [
                                            \App\Models\StoneReception::STATUS_ACTIVE    => ['bg-success', 'Активна'],
                                            \App\Models\StoneReception::STATUS_PROCESSED => ['bg-primary', 'Обработана'],
                                            \App\Models\StoneReception::STATUS_ERROR     => ['bg-danger',  'Ошибка'],
                                        ];
                                        [$badgeClass, $statusLabel] = $statusMap[$stoneReception->status] ?? ['bg-secondary', $stoneReception->status];
                                    @endphp
                                    <span class="badge {{ $badgeClass }}">{{ $statusLabel }}</span>
                                </td>
                            </tr>
                            <tr>
                                <th>Дата приёмки:</th>
                                <td>{{ $stoneReception->created_at->format('d.m.Y H:i') }}</td>
                            </tr>
                            <tr>
                                <th>Приемщик:</th>
                                <td>{{ $stoneReception->receiver->name ?? '—' }}</td>
                            </tr>
                            <tr>
                                <th>Пильщик:</th>
                                <td>{{ $stoneReception->cutter->name ?? '—' }}</td>
                            </tr>
                            <tr>
                                <th>Склад:</th>
                                <td>{{ $stoneReception->store->name ?? '—' }}</td>
                            </tr>
                            <tr>
                                <th>Партия сырья:</th>
                                <td>
                                    @if($stoneReception->rawMaterialBatch)
                                        <a href="{{ route('raw-batches.show', $stoneReception->rawMaterialBatch) }}">
                                            {{ $stoneReception->rawMaterialBatch->product->name ?? '—' }}
                                            @if($stoneReception->rawMaterialBatch->batch_number)
                                                №{{ $stoneReception->rawMaterialBatch->batch_number }}
                                            @endif
                                        </a>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>Расход сырья:</th>
                                <td>
                                    <span class="badge bg-info">
                                        {{ number_format($stoneReception->raw_quantity_used, 3) }} м³
                                    </span>
                                </td>
                            </tr>
                            @if($stoneReception->notes)
                                <tr>
                                    <th>Примечание:</th>
                                    <td>{{ $stoneReception->notes }}</td>
                                </tr>
                            @endif
                            @if($stoneReception->moysklad_processing_id)
                                <tr>
                                    <th>ID МойСклад:</th>
                                    <td><code class="small">{{ $stoneReception->moysklad_processing_id }}</code></td>
                                </tr>
                            @endif
                            @if($stoneReception->synced_at)
                                <tr>
                                    <th>Синхронизировано:</th>
                                    <td>{{ $stoneReception->synced_at->format('d.m.Y H:i') }}</td>
                                </tr>
                            @endif
                        </table>
                    </div>
                </div>

                {{-- Кнопки действий --}}
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-2">
                            <a href="{{ route('stone-receptions.edit', $stoneReception) }}"
                               class="btn btn-outline-secondary">
                                <i class="bi bi-pencil"></i> Редактировать
                            </a>

                            <form method="POST"
                                  action="{{ route('stone-receptions.copy', $stoneReception) }}"
                                  class="d-inline">
                                @csrf
                                @if($stoneReception->cutter_id)
                                    <input type="hidden" name="cutter_id" value="{{ $stoneReception->cutter_id }}">
                                @endif
                                @if($stoneReception->raw_material_batch_id)
                                    <input type="hidden" name="raw_material_batch_id"
                                           value="{{ $stoneReception->raw_material_batch_id }}">
                                @endif
                                <button type="submit" class="btn btn-outline-primary">
                                    <i class="bi bi-copy"></i> Копировать
                                </button>
                            </form>

                            @if($stoneReception->status !== \App\Models\StoneReception::STATUS_ACTIVE)
                                <form method="POST"
                                      action="{{ route('stone-receptions.reset-status', $stoneReception) }}"
                                      class="d-inline">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-outline-warning">
                                        <i class="bi bi-arrow-counterclockwise"></i> Сбросить статус
                                    </button>
                                </form>
                            @endif

                            <form method="POST"
                                  action="{{ route('stone-receptions.destroy', $stoneReception) }}"
                                  class="d-inline"
                                  onsubmit="return confirm('Удалить приёмку #{{ $stoneReception->id }}? Остатки будут возвращены в партию.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-outline-danger">
                                    <i class="bi bi-trash"></i> Удалить
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

            </div>

            {{-- Правая колонка: продукты и журнал --}}
            <div class="col-md-6">

                {{-- Продукты приёмки --}}
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">📦 Принятая продукция</h5>
                        <button type="button"
                                class="btn btn-sm btn-outline-warning"
                                id="toggleCoeffEdit"
                                title="Редактировать коэффициенты (для корректировки ошибочно заданного значения)">
                            <i class="bi bi-pencil-square"></i> Коэффициенты
                        </button>
                    </div>
                    <div class="card-body p-0">
                        @if($stoneReception->items->count() > 0)
                            {{-- Таблица просмотра --}}
                            <table class="table table-sm mb-0" id="itemsViewTable">
                                <thead class="table-light">
                                <tr>
                                    <th>Продукт</th>
                                    <th class="text-center">Подкол</th>
                                    <th class="text-end">Коэф.</th>
                                    <th class="text-end">Кол-во</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($stoneReception->items as $item)
                                    <tr>
                                        <td>
                                            @if($item->product)
                                                <a href="{{ route('products.show', $item->product->moysklad_id) }}">
                                                    {{ $item->product->name }}
                                                </a>
                                                @if($item->product->sku)
                                                    <br><small class="text-muted">{{ $item->product->sku }}</small>
                                                @endif
                                            @else
                                                <span class="text-danger">Продукт не найден (ID: {{ $item->product_id }})</span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            @if($item->is_undercut)
                                                <span class="badge bg-warning text-dark" title="80% подкол: −1.5 к коэф.">⚡ 80% подкол</span>
                                            @else
                                                <span class="text-muted small">—</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            @if($item->effective_cost_coeff !== null)
                                                <span class="badge bg-light border text-dark">
                                                    {{ number_format($item->effective_cost_coeff, 4) }}
                                                </span>
                                                @if($item->is_undercut)
                                                    <br><small class="text-muted" style="font-size:10px">
                                                        база: {{ number_format($item->base_coeff, 4) }}
                                                    </small>
                                                @endif
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            <span class="badge bg-primary">
                                                {{ number_format($item->quantity, 3) }} м²
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                                <tfoot class="table-light">
                                <tr>
                                    <th colspan="3">Итого:</th>
                                    <th class="text-end">
                                        <span class="badge bg-primary">
                                            {{ number_format($stoneReception->items->sum('quantity'), 3) }} м²
                                        </span>
                                    </th>
                                </tr>
                                @if($stoneReception->raw_quantity_used > 0)
                                    @php
                                        $totalQty = $stoneReception->items->sum('quantity');
                                        $coeff = $stoneReception->raw_quantity_used > 0
                                            ? $totalQty / $stoneReception->raw_quantity_used
                                            : 0;
                                    @endphp
                                    <tr>
                                        <th colspan="4" class="text-muted small fw-normal">
                                            Коэффициент выхода:
                                            {{ number_format($coeff * 100, 1) }}%
                                            (из 1 м³ сырья → {{ number_format($coeff, 3) }} м² плитки)
                                        </th>
                                    </tr>
                                @endif
                                </tfoot>
                            </table>

                            {{-- Форма редактирования коэффициентов (скрыта по умолчанию) --}}
                            <div id="coeffEditPanel" style="display:none" class="p-3 border-top bg-warning bg-opacity-10">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                                    <span class="small text-warning-emphasis fw-semibold">
                                        Редактирование коэффициентов — используйте только для исправления ошибочно заданных значений.
                                        Базовый коэф. берётся из поля ниже, затем применяются флаги (подкол и др.).
                                    </span>
                                </div>
                                <form method="POST"
                                      action="{{ route('stone-receptions.update-item-coeff', $stoneReception) }}"
                                      id="coeffEditForm">
                                    @csrf
                                    <table class="table table-sm mb-3">
                                        <thead class="table-light">
                                        <tr>
                                            <th>Продукт</th>
                                            <th class="text-center" style="width:160px">Базовый коэф.</th>
                                            <th class="text-center" style="width:130px">80% подкол</th>
                                            <th class="text-end" style="width:130px">Итоговый коэф.</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach($stoneReception->items as $i => $item)
                                            <tr>
                                                <td class="small">{{ $item->product->name ?? '—' }}</td>
                                                <td class="text-center">
                                                    <input type="hidden" name="items[{{ $i }}][item_id]" value="{{ $item->id }}">
                                                    <input type="number"
                                                           name="items[{{ $i }}][base_coeff]"
                                                           class="form-control form-control-sm text-center coeff-base-input"
                                                           step="0.0001"
                                                           value="{{ number_format($item->base_coeff, 4, '.', '') }}"
                                                           data-row="{{ $i }}">
                                                </td>
                                                <td class="text-center">
                                                    <div class="form-check d-flex justify-content-center">
                                                        <input class="form-check-input coeff-undercut-cb"
                                                               type="checkbox"
                                                               name="items[{{ $i }}][is_undercut]"
                                                               value="1"
                                                               data-row="{{ $i }}"
                                                               {{ $item->is_undercut ? 'checked' : '' }}>
                                                    </div>
                                                </td>
                                                <td class="text-end">
                                                    <span class="badge bg-secondary coeff-result-display"
                                                          data-row="{{ $i }}">
                                                        {{ number_format($item->effective_cost_coeff ?? 0, 4) }}
                                                    </span>
                                                </td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-sm btn-warning">
                                            <i class="bi bi-save"></i> Сохранить коэффициенты
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="cancelCoeffEdit">
                                            Отмена
                                        </button>
                                    </div>
                                </form>
                            </div>
                        @else
                            <div class="text-center py-4">
                                <i class="bi bi-box-seam display-4 text-muted"></i>
                                <p class="text-muted mt-3">Продукты не добавлены</p>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Журнал изменений --}}
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">📋 Журнал изменений</h5>
                    </div>
                    <div class="card-body p-0">
                        @if($stoneReception->receptionLogs->count() > 0)
                            <div class="list-group list-group-flush">
                                @foreach($stoneReception->receptionLogs as $log)
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                @if($log->type === \App\Models\ReceptionLog::TYPE_CREATED)
                                                    <span class="badge bg-success me-1">Создание</span>
                                                @else
                                                    <span class="badge bg-warning text-dark me-1">Изменение</span>
                                                @endif
                                                <small class="text-muted">{{ $log->created_at->format('d.m.Y H:i') }}</small>
                                            </div>
                                            @if(abs($log->raw_quantity_delta) > 0.0001)
                                                <span class="badge {{ $log->raw_quantity_delta >= 0 ? 'bg-info' : 'bg-secondary' }}">
                                                    {{ $log->raw_quantity_delta >= 0 ? '+' : '' }}{{ number_format($log->raw_quantity_delta, 3) }} м³ сырья
                                                </span>
                                            @endif
                                        </div>

                                        @if($log->items->count() > 0)
                                            <div class="mt-1">
                                                @foreach($log->items as $logItem)
                                                    <div class="small">
                                                        {{ $logItem->product->name ?? "Продукт #$logItem->product_id" }}:
                                                        <span class="{{ $logItem->quantity_delta >= 0 ? 'text-success' : 'text-danger' }}">
                                                            {{ $logItem->quantity_delta >= 0 ? '+' : '' }}{{ number_format($logItem->quantity_delta, 3) }} м²
                                                        </span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif

                                        <div class="small text-muted mt-1">
                                            Приемщик: {{ $log->receiver->name ?? '—' }}
                                            @if($log->cutter)
                                                · Пильщик: {{ $log->cutter->name }}
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="text-center py-4">
                                <p class="text-muted mb-0">Нет записей в журнале</p>
                            </div>
                        @endif
                    </div>
                </div>

            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggleBtn    = document.getElementById('toggleCoeffEdit');
    const cancelBtn    = document.getElementById('cancelCoeffEdit');
    const editPanel    = document.getElementById('coeffEditPanel');
    const viewTable    = document.getElementById('itemsViewTable');

    if (!toggleBtn || !editPanel) return;

    function showEdit() {
        editPanel.style.display = '';
        toggleBtn.classList.replace('btn-outline-warning', 'btn-warning');
    }
    function hideEdit() {
        editPanel.style.display = 'none';
        toggleBtn.classList.replace('btn-warning', 'btn-outline-warning');
    }

    toggleBtn.addEventListener('click', function () {
        editPanel.style.display === 'none' ? showEdit() : hideEdit();
    });
    cancelBtn?.addEventListener('click', hideEdit);

    // Живой пересчёт итогового коэффициента при вводе
    const UNDERCUT_PENALTY = 1.5;

    function recalcRow(rowIdx) {
        const baseInput  = document.querySelector(`.coeff-base-input[data-row="${rowIdx}"]`);
        const undercutCb = document.querySelector(`.coeff-undercut-cb[data-row="${rowIdx}"]`);
        const display    = document.querySelector(`.coeff-result-display[data-row="${rowIdx}"]`);
        if (!baseInput || !display) return;

        const base      = parseFloat(baseInput.value) || 0;
        const undercut  = undercutCb?.checked || false;
        const effective = undercut ? base - UNDERCUT_PENALTY : base;

        display.textContent = effective.toFixed(4);
        display.className   = display.className.replace(/bg-\w+/, undercut ? 'bg-warning' : 'bg-secondary');
    }

    document.querySelectorAll('.coeff-base-input').forEach(el => {
        el.addEventListener('input', () => recalcRow(el.dataset.row));
    });
    document.querySelectorAll('.coeff-undercut-cb').forEach(el => {
        el.addEventListener('change', () => recalcRow(el.dataset.row));
    });

    // Инициализация
    document.querySelectorAll('.coeff-base-input').forEach(el => recalcRow(el.dataset.row));
});
</script>
@endpush
