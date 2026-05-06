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
                                        <div class="text-muted" style="font-size:.72rem">Работник</div>
                                        <div class="small fw-semibold">{{ $stoneReception->cutter->name ?? '—' }}</div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-muted" style="font-size:.72rem">Приёмщик</div>
                                        <div class="small fw-semibold">{{ $stoneReception->receiver->name ?? '—' }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Партия сырья --}}
                        <div class="info-block">
                            <div class="info-block-header">
                                <span class="small fw-semibold text-muted">Партия сырья</span>
                            </div>
                            <div class="info-block-body">
                                @if($stoneReception->rawMaterialBatch)
                                    @include('partials.raw-batch-card', ['batch' => $stoneReception->rawMaterialBatch])
                                @else
                                    <span class="small text-muted">—</span>
                                @endif
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

                        {{-- МойСклад: статус синхронизации приёмки --}}
                        <x-moysklad-sync-status
                            :model="$stoneReception"
                            :sync-route="route('stone-receptions.sync', $stoneReception)"
                            wrapper="info-block" />

                {{-- Кнопки действий --}}
                <div class="card shadow-sm mb-3">
                    <div class="card-header bg-white py-2">
                        <span class="fw-semibold small text-muted">Возможные действия</span>
                    </div>
                    <div class="card-body py-2">
                        <div class="d-grid gap-2">

                            <a href="{{ route('stone-receptions.edit', $stoneReception) }}" class="btn btn-success">
                                <i class="bi bi-plus-lg"></i> Добавить приёмку
                            </a>

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
                        <div class="table-responsive">
                            <table class="table mb-0" style="font-size:.75rem;table-layout:auto">
                                <colgroup>
                                    <col>
                                    <col style="width:1%">
                                    <col style="width:1%">
                                    <col style="width:1%">
                                    <col style="width:1%">
                                </colgroup>
                                <thead class="table-light">
                                <tr>
                                    <th style="border-left:4px solid transparent;padding:.3rem .1rem .3rem .4rem">Продукт</th>
                                    <th class="text-end text-nowrap" style="padding:.3rem .25rem .3rem .1rem">м²</th>
                                    <th class="text-end text-nowrap" style="padding:.3rem .25rem" title="Коэффициент зафиксирован на момент приёмки">Коэф.</th>
                                    <th class="text-end text-nowrap" style="padding:.3rem .25rem">₽/м²</th>
                                    <th class="text-end text-nowrap" style="border-right:4px solid transparent;padding:.3rem .4rem .3rem .25rem">Зарплата</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($stoneReception->items as $item)
                                    @php
                                        $skuColor = \App\Models\Product::getColorBySku($item->product?->sku);
                                        $skuBg    = $skuColor === '#FFFFFF' ? '' : 'background:' . $skuColor . '18;';
                                    @endphp
                                    <tr>
                                        <td style="border-left:4px solid {{ $skuColor }};{{ $skuBg }};word-break:break-word;padding:.3rem .1rem .3rem .4rem">
                                            @if($item->product)
                                                <a href="{{ route('products.show', $item->product->moysklad_id) }}"
                                                   class="text-body text-decoration-none">{{ $item->product->name }}</a>
                                            @else
                                                <span class="text-danger">Продукт не найден</span>
                                            @endif
                                            @if($item->is_undercut)
                                                <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem">подкол 80%</span>
                                            @endif
                                            @if($item->is_small_tile)
                                                <span class="badge bg-info text-dark ms-1" style="font-size:.6rem">< 50мм</span>
                                            @endif
                                        </td>
                                        <td class="text-end text-nowrap" style="{{ $skuBg }};padding:.3rem .25rem .3rem .1rem">
                                            {{ number_format($item->quantity, 3, ',', ' ') }}
                                        </td>
                                        <td class="text-end text-nowrap text-muted" style="{{ $skuBg }};padding:.3rem .25rem">
                                            @if($item->effective_cost_coeff !== null)
                                                ×{{ number_format($item->effective_cost_coeff, 1, ',', ' ') }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="text-end text-nowrap text-muted" style="{{ $skuBg }};padding:.3rem .25rem">
                                            @if($item->worker_cost_per_m2 !== null)
                                                {{ number_format($item->worker_cost_per_m2, 0, '.', ' ') }} ₽
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="text-end text-nowrap fw-semibold text-success" style="border-right:4px solid {{ $skuColor }};{{ $skuBg }};padding:.3rem .4rem .3rem .25rem">
                                            @if($item->worker_cost_per_m2 !== null)
                                                {{ number_format($item->calculateWorkerPay(), 0, '.', ' ') }} ₽
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                                <tfoot class="table-light">
                                <tr>
                                    <th class="fw-bold" style="padding:.3rem .1rem .3rem .4rem">ИТОГО:</th>
                                    <th class="text-end text-nowrap fw-semibold" style="font-size:.9rem;padding:.3rem .25rem .3rem .1rem">
                                        {{ number_format($stoneReception->items->sum('quantity'), 3, ',', ' ') }} м²
                                    </th>
                                    <th></th>
                                    <th></th>
                                    <th class="text-end text-nowrap text-success" style="font-size:.9rem;padding:.3rem .4rem .3rem .25rem">
                                        {{ number_format($stoneReception->items->sum(fn($i) => $i->calculateWorkerPay()), 0, '.', ' ') }} ₽
                                    </th>
                                </tr>
                                @if($stoneReception->raw_quantity_used > 0)
                                    @php
                                        $totalQty = $stoneReception->items->sum('quantity');
                                        $coeff = $totalQty / $stoneReception->raw_quantity_used;
                                    @endphp
                                    <tr>
                                        <th colspan="5" class="text-muted fw-normal" style="padding:.3rem .4rem">
                                            Коэф. выхода: {{ number_format($coeff * 100, 1) }}%
                                            (1 м³ → {{ number_format($coeff, 3) }} м²)
                                        </th>
                                    </tr>
                                @endif
                                </tfoot>
                            </table>
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

                {{-- Расходы производства (только для администратора) --}}
                @if(auth()->user()->is_admin)
                @php
                    $costTotalQty   = $stoneReception->items->sum('quantity');
                    $costWorkerPay  = $stoneReception->items->sum(fn($i) => $i->calculateWorkerPay());
                    $costBatch      = $stoneReception->rawMaterialBatch;
                    $costReception  = round((float)($costBatch?->processing_sum ?? 0) * $costTotalQty);
                    $costOther      = 0;
                    $costGrandTotal = $costWorkerPay + $costReception + $costOther;
                @endphp
                <div class="card shadow-sm mb-3">
                    <div style="background:#f8f9fa;padding:.3rem .5rem;border-bottom:1px solid #dee2e6;border-radius:.35rem .35rem 0 0">
                        <span class="small fw-semibold">💰 Расходы производства</span>
                    </div>
                    <table class="table table-sm mb-0">
                        <tbody>
                        <tr>
                            <td>Зарплата рабочим</td>
                            <td class="text-end text-nowrap fw-semibold">{{ number_format($costWorkerPay, 0, '.', ' ') }} ₽</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Затраты на приёмку</td>
                            <td class="text-end text-nowrap text-muted">{{ number_format($costReception, 0, '.', ' ') }} ₽</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Прочие расходы</td>
                            <td class="text-end text-nowrap text-muted">{{ number_format($costOther, 0, '.', ' ') }} ₽</td>
                        </tr>
                        </tbody>
                        <tfoot class="table-secondary">
                        <tr>
                            <th>Итого</th>
                            <th class="text-end text-nowrap">{{ number_format($costGrandTotal, 0, '.', ' ') }} ₽</th>
                        </tr>
                        </tfoot>
                    </table>
                </div>
                @endif

                {{-- Журнал изменений --}}
                <div class="card shadow-sm">
                    <div style="background:#f8f9fa;padding:.3rem .5rem;border-bottom:1px solid #dee2e6;border-radius:.35rem .35rem 0 0">
                        <span class="small fw-semibold">📋 Журнал изменений</span>
                    </div>
                    @if($stoneReception->receptionLogs->count() > 0)
                        <div class="p-2">
                            @foreach($stoneReception->receptionLogs as $log)
                                @include('partials.reception-log-card', [
                                    'log'             => $log,
                                    'showActions'     => false,
                                    'showRawDetails'  => true,
                                    'showStoreBottom' => false,
                                    'isMaster'        => false,
                                ])
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

    const UNDERCUT_PENALTY = {{ (float) \App\Models\Setting::get('UNDERCUT_PENALTY', 1.5) }};

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
