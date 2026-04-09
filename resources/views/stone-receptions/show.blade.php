@extends('layouts.app')

@section('title', 'Приёмка #' . $stoneReception->id)

@section('content')
    <div class="container py-3 py-md-4">

        <x-page-header
            title="🪨 Приёмка #{{ $stoneReception->id }}"
            back-url="{{ $backUrl }}"
            back-label="К списку"
        />

        @include('partials.alerts')

        <div class="row g-3">

            {{-- Левая колонка: основная информация + кнопки --}}
            <div class="col-md-6">

                {{-- Основная информация --}}
                <div class="card shadow-sm mb-3">
                    <div style="padding:.4rem .5rem">

                        @php
                            $statusMap = [
                                \App\Models\StoneReception::STATUS_ACTIVE    => ['bg-success',          'Активна'],
                                \App\Models\StoneReception::STATUS_COMPLETED => ['bg-warning text-dark', 'Завершена'],
                                \App\Models\StoneReception::STATUS_PROCESSED => ['bg-primary',           'Обработана'],
                                \App\Models\StoneReception::STATUS_ERROR     => ['bg-danger',             'Ошибка'],
                            ];
                            [$badgeClass, $statusLabel] = $statusMap[$stoneReception->status] ?? ['bg-secondary', $stoneReception->status];
                        @endphp

                        {{-- Статус + дата --}}
                        <div class="info-block">
                            <div class="info-block-header">
                                <span class="small fw-semibold text-muted">Статус</span>
                            </div>
                            <div class="info-block-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="badge {{ $badgeClass }}">{{ $statusLabel }}</span>
                                    <span class="text-muted small">{{ $stoneReception->created_at->format('d.m.Y H:i') }}</span>
                                </div>
                                @if($stoneReception->synced_at)
                                    <div class="text-muted mt-1" style="font-size:.75rem">
                                        <i class="bi bi-cloud-check me-1"></i>Синхронизировано: {{ $stoneReception->synced_at->format('d.m.Y H:i') }}
                                    </div>
                                @endif
                            </div>
                        </div>

                        {{-- Участники --}}
                        <div class="info-block">
                            <div class="info-block-header">
                                <span class="small fw-semibold text-muted">Участники</span>
                            </div>
                            <div class="info-block-body">
                                <div class="row g-1">
                                    <div class="col-6">
                                        <div class="text-muted" style="font-size:.72rem">Пильщик</div>
                                        <div class="small fw-semibold">{{ $stoneReception->cutter->name ?? '—' }}</div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-muted" style="font-size:.72rem">Приёмщик</div>
                                        <div class="small fw-semibold">{{ $stoneReception->receiver->name ?? '—' }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Партия и расход --}}
                        <div class="info-block">
                            <div class="info-block-header">
                                <span class="small fw-semibold text-muted">Партия сырья</span>
                            </div>
                            <div class="info-block-body">
                                <div class="row g-1">
                                    <div class="col-8">
                                        <div class="text-muted" style="font-size:.72rem">Партия</div>
                                        @if($stoneReception->rawMaterialBatch)
                                            <a href="{{ route('raw-batches.show', $stoneReception->rawMaterialBatch) }}" class="small fw-semibold">
                                                {{ $stoneReception->rawMaterialBatch->product->name ?? '—' }}
                                                @if($stoneReception->rawMaterialBatch->batch_number)
                                                    №{{ $stoneReception->rawMaterialBatch->batch_number }}
                                                @endif
                                            </a>
                                        @else
                                            <span class="small text-muted">—</span>
                                        @endif
                                    </div>
                                    <div class="col-4">
                                        <div class="text-muted" style="font-size:.72rem">Расход</div>
                                        <span class="badge bg-info">{{ number_format($stoneReception->raw_quantity_used, 3) }} м³</span>
                                    </div>
                                </div>
                                <div class="d-flex gap-3 mt-1" style="font-size:.72rem">
                                    <span class="text-muted">Склад: {{ $stoneReception->store->name ?? '—' }}</span>
                                    @if($stoneReception->rawMaterialBatch)
                                        <span class="text-muted">Остаток:
                                            <span class="fw-semibold text-dark">{{ number_format($stoneReception->rawMaterialBatch->remaining_quantity, 3) }} м³</span>
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        @if($stoneReception->notes)
                            <div class="info-block">
                                <div class="info-block-header">
                                    <span class="small fw-semibold text-muted">Примечание</span>
                                </div>
                                <div class="info-block-body">
                                    <span class="small">{{ $stoneReception->notes }}</span>
                                </div>
                            </div>
                        @endif

                        {{-- МойСклад: статус техоперации партии --}}
                        @php $batchForSync = $stoneReception->rawMaterialBatch; @endphp
                        <div class="info-block">
                            <div class="info-block-header">
                                <span class="small fw-semibold text-muted"><i class="bi bi-cloud me-1"></i>МойСклад</span>
                            </div>
                            <div class="info-block-body">
                                @if($batchForSync?->hasSyncError())
                                    <div class="small text-warning-emphasis">
                                        <i class="bi bi-exclamation-triangle me-1"></i>
                                        <strong>Ошибка:</strong> {{ $batchForSync->moysklad_sync_error }}
                                    </div>
                                @elseif($batchForSync?->hasMoySkladProcessing())
                                    <div class="small text-success">
                                        <i class="bi bi-check-circle me-1"></i>
                                        Синхронизировано
                                        @if($batchForSync->moysklad_processing_name)
                                            · <span class="text-muted">{{ $batchForSync->moysklad_processing_name }}</span>
                                        @endif
                                    </div>
                                    @if(auth()->user()->is_admin)
                                        <div class="text-muted mt-1" style="font-size:.72rem;word-break:break-all">
                                            <i class="bi bi-fingerprint me-1"></i>
                                            <code style="font-size:.7rem">{{ $batchForSync->moysklad_processing_id }}</code>
                                        </div>
                                    @endif
                                @else
                                    <div class="small text-muted">
                                        <i class="bi bi-cloud-slash me-1"></i>Техоперация не создана
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Кнопки действий --}}
                <div class="card shadow-sm mb-3">
                    <div class="card-header bg-white py-2">
                        <span class="fw-semibold small text-muted">Возможные действия</span>
                    </div>
                    <div class="card-body py-2">
                        <div class="d-grid gap-2">

                            <a href="{{ route('stone-receptions.edit', $stoneReception) }}" class="btn btn-secondary">
                                <i class="bi bi-pencil"></i> Редактировать
                            </a>

                            @php
                                $hasSyncError = $stoneReception->rawMaterialBatch?->hasSyncError();
                                $hasBatchProcessing = $stoneReception->rawMaterialBatch?->hasMoySkladProcessing();
                            @endphp
                            <form method="POST"
                                  action="{{ route('stone-receptions.sync', $stoneReception) }}">
                                @csrf
                                <button type="submit"
                                        class="btn w-100 {{ $hasSyncError ? 'btn-warning' : ($hasBatchProcessing ? 'btn-outline-secondary' : 'btn-outline-primary') }}">
                                    <i class="bi bi-arrow-repeat me-1"></i>
                                    {{ $hasBatchProcessing ? 'Синхронизировать с МойСклад' : 'Создать техоперацию' }}
                                </button>
                            </form>

                            <form method="POST" action="{{ route('stone-receptions.copy', $stoneReception) }}">
                                @csrf
                                @if($stoneReception->cutter_id)
                                    <input type="hidden" name="cutter_id" value="{{ $stoneReception->cutter_id }}">
                                @endif
                                @if($stoneReception->raw_material_batch_id)
                                    <input type="hidden" name="raw_material_batch_id" value="{{ $stoneReception->raw_material_batch_id }}">
                                @endif
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-copy"></i> Новая приёмка на основе текущей
                                </button>
                            </form>

                            @if($stoneReception->status === \App\Models\StoneReception::STATUS_ACTIVE
                                && $stoneReception->rawMaterialBatch
                                && (float)$stoneReception->rawMaterialBatch->remaining_quantity <= 0)
                                <form method="POST"
                                      action="{{ route('stone-receptions.mark-completed', $stoneReception) }}"
                                      onsubmit="return confirm('Отметить приёмку как «Завершена»?\nСырьё в партии закончилось.')">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-warning w-100">
                                        <i class="bi bi-check2-circle"></i> Завершена
                                        <span class="opacity-75 small">(сырьё израсходовано)</span>
                                    </button>
                                </form>
                            @endif

                            @if($stoneReception->status !== \App\Models\StoneReception::STATUS_ACTIVE)
                                <form method="POST"
                                      action="{{ route('stone-receptions.reset-status', $stoneReception) }}"
                                      onsubmit="return confirm('Вернуть приёмку в статус «Активна»?')">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-success w-100">
                                        <i class="bi bi-arrow-counterclockwise"></i> Вернуть в работу
                                    </button>
                                </form>
                            @endif

                            <form method="POST"
                                  action="{{ route('stone-receptions.destroy', $stoneReception) }}"
                                  onsubmit="return confirm('Удалить приёмку #{{ $stoneReception->id }}? Остатки будут возвращены в партию.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger w-100">
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
                <div class="card shadow-sm mb-3">
                    <div style="background:#f8f9fa;padding:.3rem .5rem;border-bottom:1px solid #dee2e6;border-radius:.35rem .35rem 0 0;display:flex;justify-content:space-between;align-items:center">
                        <span class="small fw-semibold">📦 Принятая продукция</span>
                        <div class="d-flex gap-1">
                            <form method="POST"
                                  action="{{ route('stone-receptions.refresh-item-coeffs', $stoneReception) }}"
                                  onsubmit="return confirm('Обновить коэффициенты из справочника товаров?\nЗначения effective_cost_coeff будут пересчитаны по текущим prod_cost_coeff.')">
                                @csrf
                                <button type="submit"
                                        class="btn btn-sm btn-outline-secondary"
                                        title="Обновить коэффициенты из справочника товаров">
                                    <i class="bi bi-arrow-repeat"></i>
                                    <span class="d-none d-sm-inline ms-1">Обновить коэф.</span>
                                </button>
                            </form>
                            <button type="button"
                                    class="btn btn-sm btn-outline-warning"
                                    id="toggleCoeffEdit"
                                    title="Редактировать коэффициенты">
                                <i class="bi bi-pencil-square"></i>
                                <span class="d-none d-sm-inline ms-1">Коэффициенты</span>
                            </button>
                        </div>
                    </div>

                    @if($stoneReception->items->count() > 0)
                        {{-- Десктоп: таблица --}}
                        <div class="d-none d-md-block" id="itemsViewTable">
                            <table class="table table-sm mb-0">
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
                                                <a href="{{ route('products.show', $item->product->moysklad_id) }}">{{ $item->product->name }}</a>
                                                @if($item->product->sku)
                                                    <br><small class="text-muted">{{ $item->product->sku }}</small>
                                                @endif
                                            @else
                                                <span class="text-danger small">Продукт не найден</span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            @if($item->is_undercut)
                                                <span class="badge bg-warning text-dark">⚡ 80%</span>
                                            @else
                                                <span class="text-muted small">—</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            @if($item->effective_cost_coeff !== null)
                                                <span class="badge bg-light border text-dark">{{ number_format($item->effective_cost_coeff, 4) }}</span>
                                                @if($item->is_undercut)
                                                    <br><small class="text-muted" style="font-size:10px">база: {{ number_format($item->base_coeff, 4) }}</small>
                                                @endif
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            <span class="badge bg-primary">{{ number_format($item->quantity, 3) }} м²</span>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                                <tfoot class="table-light">
                                <tr>
                                    <th colspan="3">Итого:</th>
                                    <th class="text-end">
                                        <span class="badge bg-primary">{{ number_format($stoneReception->items->sum('quantity'), 3) }} м²</span>
                                    </th>
                                </tr>
                                @if($stoneReception->raw_quantity_used > 0)
                                    @php
                                        $totalQty = $stoneReception->items->sum('quantity');
                                        $coeff = $totalQty / $stoneReception->raw_quantity_used;
                                    @endphp
                                    <tr>
                                        <th colspan="4" class="text-muted small fw-normal">
                                            Коэф. выхода: {{ number_format($coeff * 100, 1) }}%
                                            (1 м³ → {{ number_format($coeff, 3) }} м²)
                                        </th>
                                    </tr>
                                @endif
                                </tfoot>
                            </table>
                        </div>

                        {{-- Мобильный: карточки --}}
                        <div class="d-md-none" id="itemsViewMobile" style="padding:.35rem .4rem">
                            @foreach($stoneReception->items as $item)
                                <div style="border-bottom:1px solid #f0f0f0;padding:.3rem 0">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1 me-2">
                                            @if($item->product)
                                                <div class="small fw-semibold" style="font-size:.82rem">{{ $item->product->name }}</div>
                                                @if($item->product->sku)
                                                    <div class="text-muted" style="font-size:.72rem">{{ $item->product->sku }}</div>
                                                @endif
                                            @else
                                                <div class="text-danger small">Продукт не найден</div>
                                            @endif
                                            <div class="d-flex align-items-center gap-1 mt-1">
                                                @if($item->is_undercut)
                                                    <span class="badge bg-warning text-dark" style="font-size:.68rem">⚡ 80% подкол</span>
                                                @endif
                                                @if($item->effective_cost_coeff !== null)
                                                    <span class="badge bg-light border text-dark" style="font-size:.68rem">
                                                        коэф: {{ number_format($item->effective_cost_coeff, 4) }}
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                        <span class="badge bg-primary flex-shrink-0">{{ number_format($item->quantity, 3) }} м²</span>
                                    </div>
                                </div>
                            @endforeach
                            {{-- Итого мобильный --}}
                            <div style="padding:.3rem 0" class="d-flex justify-content-between align-items-center">
                                <span class="small fw-semibold">Итого:</span>
                                <span class="badge bg-primary">{{ number_format($stoneReception->items->sum('quantity'), 3) }} м²</span>
                            </div>
                            @if($stoneReception->raw_quantity_used > 0)
                                @php
                                    $totalQty = $stoneReception->items->sum('quantity');
                                    $coeff = $totalQty / $stoneReception->raw_quantity_used;
                                @endphp
                                <div class="text-muted" style="font-size:.72rem">
                                    Коэф. выхода: {{ number_format($coeff * 100, 1) }}%
                                    (1 м³ → {{ number_format($coeff, 3) }} м²)
                                </div>
                            @endif
                        </div>

                        {{-- Форма редактирования коэффициентов (скрыта по умолчанию) --}}
                        <div id="coeffEditPanel" style="display:none" class="p-2 border-top bg-warning bg-opacity-10">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                                <span class="small text-warning-emphasis fw-semibold">
                                    Только для исправления ошибочно заданных значений.
                                </span>
                            </div>
                            <form method="POST"
                                  action="{{ route('stone-receptions.update-item-coeff', $stoneReception) }}"
                                  id="coeffEditForm">
                                @csrf
                                <div style="overflow-x:auto">
                                    <table class="table table-sm mb-2" style="min-width:400px">
                                        <thead class="table-light">
                                        <tr>
                                            <th>Продукт</th>
                                            <th class="text-center" style="width:130px">Базовый коэф.</th>
                                            <th class="text-center" style="width:90px">80% подкол</th>
                                            <th class="text-end" style="width:100px">Итог</th>
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
                                                    <span class="badge bg-secondary coeff-result-display" data-row="{{ $i }}">
                                                        {{ number_format($item->effective_cost_coeff ?? 0, 4) }}
                                                    </span>
                                                </td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-sm btn-warning">
                                        <i class="bi bi-save"></i> Сохранить
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="cancelCoeffEdit">
                                        Отмена
                                    </button>
                                </div>
                            </form>
                        </div>
                    @else
                        <div class="text-center py-4">
                            <i class="bi bi-box-seam fs-3 text-muted d-block mb-1"></i>
                            <p class="text-muted small mb-0">Продукты не добавлены</p>
                        </div>
                    @endif
                </div>

                {{-- Журнал изменений --}}
                <div class="card shadow-sm">
                    <div style="background:#f8f9fa;padding:.3rem .5rem;border-bottom:1px solid #dee2e6;border-radius:.35rem .35rem 0 0">
                        <span class="small fw-semibold">📋 Журнал изменений</span>
                    </div>
                    @if($stoneReception->receptionLogs->count() > 0)
                        <div>
                            @foreach($stoneReception->receptionLogs as $log)
                                <div style="padding:.35rem .5rem;border-bottom:1px solid #f0f0f0">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="d-flex align-items-center gap-1">
                                            @if($log->type === \App\Models\ReceptionLog::TYPE_CREATED)
                                                <span class="badge bg-success" style="font-size:.7rem">Создание</span>
                                            @else
                                                <span class="badge bg-warning text-dark" style="font-size:.7rem">Изменение</span>
                                            @endif
                                            <span class="text-muted" style="font-size:.72rem">{{ $log->created_at->format('d.m H:i') }}</span>
                                        </div>
                                        @if(abs($log->raw_quantity_delta) > 0.0001)
                                            <span class="badge {{ $log->raw_quantity_delta >= 0 ? 'bg-info' : 'bg-secondary' }}" style="font-size:.7rem">
                                                {{ $log->raw_quantity_delta >= 0 ? '+' : '' }}{{ number_format($log->raw_quantity_delta, 3) }} м³
                                            </span>
                                        @endif
                                    </div>
                                    @if($log->items->count() > 0)
                                        <div class="mt-1">
                                            @foreach($log->items as $logItem)
                                                <div style="font-size:.78rem">
                                                    {{ $logItem->product->name ?? "Продукт #$logItem->product_id" }}:
                                                    <span class="{{ $logItem->quantity_delta >= 0 ? 'text-success' : 'text-danger' }} fw-semibold">
                                                        {{ $logItem->quantity_delta >= 0 ? '+' : '' }}{{ number_format($logItem->quantity_delta, 3) }} м²
                                                    </span>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                    <div class="text-muted mt-1" style="font-size:.72rem">
                                        {{ $log->receiver->name ?? '—' }}
                                        @if($log->cutter) · {{ $log->cutter->name }} @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-3">
                            <p class="text-muted small mb-0">Нет записей в журнале</p>
                        </div>
                    @endif
                </div>

            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggleBtn = document.getElementById('toggleCoeffEdit');
    const cancelBtn = document.getElementById('cancelCoeffEdit');
    const editPanel = document.getElementById('coeffEditPanel');

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

    document.querySelectorAll('.coeff-base-input').forEach(el => recalcRow(el.dataset.row));
});
</script>
@endpush
