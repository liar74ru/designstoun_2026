@extends('layouts.app')
@section('title', 'Цех #' . $workshop->id)

@section('content')
<div class="container py-3 py-md-4" style="max-width:1100px">

    <x-page-header title="Цех #{{ $workshop->id }}" :back-url="$backUrl" mobileTitle="Цех" />

    @include('partials.alerts')

    @php
        $statusMap = [
            'active'    => ['bg-success',           'Активна'],
            'completed' => ['bg-warning text-dark', 'Закрыта'],
        ];
        [$badgeClass, $statusLabel] = $statusMap[$workshop->status] ?? ['bg-danger', 'Ошибка'];
    @endphp

    <div class="row g-3">

        {{-- Левая колонка: основная информация + кнопки --}}
        <div class="col-md-6">

            {{-- Статус --}}
            <div class="info-block">
                <div class="info-block-header">
                    <span class="small fw-semibold text-muted">Статус</span>
                </div>
                <div class="info-block-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="badge {{ $badgeClass }}">{{ $statusLabel }}</span>
                        <span class="text-muted small">{{ $workshop->created_at->format('d.m.Y H:i') }}</span>
                    </div>
                    @if($workshop->synced_at)
                        <div class="text-muted mt-1" style="font-size:.75rem">
                            <i class="bi bi-cloud-check me-1"></i>Синхронизировано: {{ $workshop->synced_at->format('d.m.Y H:i') }}
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
                            <div class="small fw-semibold">{{ $workshop->packer->name ?? '—' }}</div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted" style="font-size:.72rem">Приёмщик</div>
                            <div class="small fw-semibold">{{ $workshop->receiver->name ?? '—' }}</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Отдел --}}
            <div class="info-block">
                <div class="info-block-header">
                    <span class="small fw-semibold text-muted">Отдел</span>
                </div>
                <div class="info-block-body">
                    @if(auth()->user()->isAdmin())
                        <form method="POST"
                              action="{{ route('workshops.update-department', $workshop) }}"
                              class="d-flex gap-2 align-items-center"
                              data-submit-guard>
                            @csrf
                            @method('PATCH')
                            <select name="department_id"
                                    class="form-select form-select-sm"
                                    style="font-size:.8rem;padding:.18rem .35rem;border-radius:.4rem"
                                    required>
                                <option value="">— Выберите отдел —</option>
                                @foreach($departments as $department)
                                    <option value="{{ $department->id }}"
                                        {{ $workshop->department_id == $department->id ? 'selected' : '' }}>
                                        {{ $department->name }}
                                    </option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-sm btn-primary" style="flex-shrink:0" title="Сохранить отдел">
                                <i class="bi bi-save"></i>
                            </button>
                        </form>
                    @else
                        <span class="small">{{ $workshop->department?->name ?? '—' }}</span>
                    @endif
                </div>
            </div>

            {{-- Склады --}}
            <div class="info-block">
                <div class="info-block-header">
                    <span class="small fw-semibold text-muted">Склады</span>
                </div>
                <div class="info-block-body">
                    @if($workshop->status === 'active')
                        <form method="POST"
                              action="{{ route('workshops.update-stores', $workshop) }}"
                              data-submit-guard>
                            @csrf
                            @method('PATCH')
                            <div class="text-muted" style="font-size:.72rem">Сырьё и тара</div>
                            <select name="store_id"
                                    class="form-select form-select-sm mb-1"
                                    style="font-size:.8rem;padding:.18rem .35rem;border-radius:.4rem"
                                    required>
                                <option value="">— Выберите склад —</option>
                                @foreach($stores as $store)
                                    <option value="{{ $store->id }}"
                                        {{ $workshop->store_id == $store->id ? 'selected' : '' }}>
                                        {{ $store->name }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="text-muted" style="font-size:.72rem">Продукт</div>
                            <div class="d-flex gap-2 align-items-center">
                                <select name="product_store_id"
                                        class="form-select form-select-sm"
                                        style="font-size:.8rem;padding:.18rem .35rem;border-radius:.4rem"
                                        required>
                                    <option value="">— Выберите склад —</option>
                                    @foreach($stores as $store)
                                        <option value="{{ $store->id }}"
                                            {{ ($workshop->product_store_id ?? $workshop->store_id) == $store->id ? 'selected' : '' }}>
                                            {{ $store->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-sm btn-primary" style="flex-shrink:0" title="Сохранить склады">
                                    <i class="bi bi-save"></i>
                                </button>
                            </div>
                            <div class="form-text" style="font-size:.7rem">При смене склада сырья остаток тары переносится на новый склад.</div>
                        </form>
                    @else
                        <div class="row g-1">
                            <div class="col-6">
                                <div class="text-muted" style="font-size:.72rem">Сырьё и тара</div>
                                <div class="small fw-semibold">{{ $workshop->store->name ?? '—' }}</div>
                            </div>
                            <div class="col-6">
                                <div class="text-muted" style="font-size:.72rem">Продукт</div>
                                <div class="small fw-semibold">{{ ($workshop->productStore ?? $workshop->store)?->name ?? '—' }}</div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Затраты на производство --}}
            <div class="info-block">
                <div class="info-block-header">
                    <span class="small fw-semibold text-muted">Затраты на производство</span>
                </div>
                <div class="info-block-body">
                    @if($workshop->manual_processing_sum !== null)
                        <span class="badge bg-secondary">{{ number_format((float) $workshop->manual_processing_sum, 2) }} ₽/ед</span>
                        <span class="text-muted small">(ручной ввод)</span>
                    @else
                        <span class="small text-muted">Автоматически (по зарплате работника)</span>
                    @endif
                </div>
            </div>

            {{-- Примечание --}}
            @if($workshop->notes)
                <div class="info-block">
                    <div class="info-block-header">
                        <span class="small fw-semibold text-muted">Примечание</span>
                    </div>
                    <div class="info-block-body">
                        <span class="small">{{ $workshop->notes }}</span>
                    </div>
                </div>
            @endif

            {{-- МойСклад: статус синхронизации --}}
            <x-moysklad-sync-status
                :model="$workshop"
                :sync-route="route('workshops.sync', $workshop)"
                wrapper="info-block" />

            {{-- Кнопки действий --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white py-2">
                    <span class="fw-semibold small text-muted">Возможные действия</span>
                </div>
                <div class="card-body py-2">
                    <div class="d-grid gap-2">

                        @if($workshop->status === 'active')
                            <a href="{{ route('workshops.edit', $workshop) }}" class="btn btn-success">
                                <i class="bi bi-pencil"></i> Редактировать
                            </a>

                            <form method="POST"
                                  action="{{ route('workshops.mark-completed', $workshop) }}"
                                  onsubmit="return confirm('Закрыть операцию?')"
                                  data-submit-guard>
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn btn-warning w-100">
                                    <i class="bi bi-check2-circle"></i> Закрыть
                                </button>
                            </form>
                        @else
                            <form method="POST"
                                  action="{{ route('workshops.reset-status', $workshop) }}"
                                  onsubmit="return confirm('Сбросить статус?')"
                                  data-submit-guard>
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="bi bi-arrow-counterclockwise"></i> Активировать
                                </button>
                            </form>
                        @endif

                        <form method="POST" action="{{ route('workshops.copy', $workshop) }}" data-submit-guard>
                            @csrf
                            <button type="submit" class="btn btn-outline-info w-100">
                                <i class="bi bi-copy"></i> Копировать
                            </button>
                        </form>

                        <form method="POST"
                              action="{{ route('workshops.destroy', $workshop) }}"
                              onsubmit="return confirm('Удалить операцию Цех #{{ $workshop->id }}? Тара будет возвращена на склад.')"
                              data-submit-guard>
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

        {{-- Правая колонка: позиции + лог --}}
        <div class="col-md-6">

            @php
                $rawItems     = $workshop->items->where('role', \App\Models\WorkshopItem::ROLE_RAW);
                $packageItems = $workshop->items->where('role', \App\Models\WorkshopItem::ROLE_PACKAGE);
                $productItems = $workshop->items->where('role', \App\Models\WorkshopItem::ROLE_PRODUCT);
            @endphp

            {{-- Сырьё --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                    <span class="small fw-semibold text-muted"><i class="bi bi-box me-1"></i> Сырьё</span>
                    <span class="text-muted small">Итого: <strong>{{ number_format($rawItems->sum('quantity'), 3) }}</strong></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Товар</th>
                                <th class="text-end">Кол-во</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($rawItems as $item)
                            <tr>
                                <td>
                                    <div>{{ $item->product->name }}</div>
                                    <small class="text-muted">{{ $item->product->sku }}</small>
                                </td>
                                <td class="text-end">{{ number_format($item->quantity, 3) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="text-muted text-center">—</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Упаковка --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white py-2">
                    <span class="small fw-semibold text-muted"><i class="bi bi-box-seam me-1"></i> Упаковка</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Тара</th>
                                <th class="text-end">Кол-во</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($packageItems as $item)
                            <tr>
                                <td>
                                    <div>{{ $item->product->name }}</div>
                                    <small class="text-muted">{{ $item->product->sku }}</small>
                                </td>
                                <td class="text-end">{{ number_format($item->quantity, 3) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="text-muted text-center">—</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Продукт --}}
            <div class="card shadow-sm mb-3">
                <div style="background:#f8f9fa;padding:.3rem .5rem;border-bottom:1px solid #dee2e6;border-radius:.35rem .35rem 0 0;display:flex;justify-content:space-between;align-items:center">
                    <span class="small fw-semibold"><i class="bi bi-check2-circle me-1"></i> Продукт</span>
                    @if($productItems->isNotEmpty())
                        <div class="d-flex gap-1">
                            <form method="POST"
                                  action="{{ route('workshops.refresh-item-coeffs', $workshop) }}"
                                  onsubmit="return confirm('Обновить коэффициенты из справочника товаров?\nЗначения effective_cost_coeff будут пересчитаны по текущим prod_cost_coeff.')"
                                  data-submit-guard>
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
                    @endif
                </div>

                @if($productItems->isNotEmpty())
                    <div class="table-responsive">
                        <table class="table table-sm mb-0" style="font-size:.8rem">
                            <thead class="table-light">
                                <tr>
                                    <th>Товар</th>
                                    <th class="text-end text-nowrap">Кол-во</th>
                                    <th style="width:1%"></th>
                                    <th class="text-end text-nowrap" title="Коэффициент зафиксирован при создании">Коэф.</th>
                                    <th class="text-end text-nowrap">₽/м²</th>
                                    <th class="text-end text-nowrap">Зарплата</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach($productItems as $item)
                                <tr>
                                    <td>
                                        <div>{{ $item->product->name ?? '—' }}</div>
                                        <small class="text-muted">{{ $item->product->sku ?? '' }}</small>
                                        @include('partials.modifier-badges', [
                                            'modifiers' => $item->modifiers,
                                            'class'     => 'ms-1',
                                            'font'      => '.6rem',
                                        ])
                                    </td>
                                    <td class="text-end text-nowrap">{{ number_format($item->quantity, 3) }}</td>
                                    {{-- Метка роли: верхняя строка — пильщик, нижняя — мастер --}}
                                    <td class="text-center text-muted">
                                        <div style="font-size:.7rem;line-height:1.5" title="Пильщик"><i class="bi bi-person"></i></div>
                                        <div style="font-size:.7rem;line-height:1.5" title="Мастер"><i class="bi bi-person-gear"></i></div>
                                    </td>
                                    <td class="text-end text-nowrap text-muted">
                                        <div style="font-size:.7rem;line-height:1.5">
                                            @if($item->effective_cost_coeff !== null)
                                                ×{{ \App\Support\RateFormula::formatCoeff($item->effective_cost_coeff) }}
                                            @else
                                                —
                                            @endif
                                        </div>
                                        <div style="font-size:.7rem;line-height:1.5">
                                            @if($item->master_effective_cost_coeff !== null)
                                                ×{{ \App\Support\RateFormula::formatCoeff($item->master_effective_cost_coeff) }}
                                            @else
                                                —
                                            @endif
                                        </div>
                                    </td>
                                    <td class="text-end text-nowrap text-muted">
                                        <div style="font-size:.7rem;line-height:1.5">
                                            @if($item->worker_cost_per_m2 !== null)
                                                {{ number_format($item->worker_cost_per_m2, 0, '.', ' ') }} ₽
                                            @else
                                                —
                                            @endif
                                        </div>
                                        <div style="font-size:.7rem;line-height:1.5">
                                            @if($item->master_cost_per_m2 !== null)
                                                {{ number_format($item->master_cost_per_m2, 0, '.', ' ') }} ₽
                                            @else
                                                —
                                            @endif
                                        </div>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <div class="fw-semibold text-success" style="font-size:.7rem;line-height:1.5">
                                            {{ number_format($item->calculateWorkerPay(), 2) }} ₽
                                        </div>
                                        <div class="text-muted" style="font-size:.7rem;line-height:1.5">
                                            {{ number_format($item->calculateMasterPay(), 2) }} ₽
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th>ИТОГО:</th>
                                    <th class="text-end text-nowrap fw-semibold">{{ number_format($productItems->sum('quantity'), 3) }}</th>
                                    <th class="text-center text-muted fw-normal">
                                        <div style="font-size:.75rem;line-height:1.5" title="Пильщик"><i class="bi bi-person"></i></div>
                                        <div style="font-size:.75rem;line-height:1.5" title="Мастер"><i class="bi bi-person-gear"></i></div>
                                    </th>
                                    <th></th>
                                    <th></th>
                                    <th class="text-end text-nowrap fw-semibold">
                                        <div class="text-success" style="font-size:.75rem;line-height:1.5">
                                            {{ number_format($productItems->sum(fn($i) => $i->calculateWorkerPay()), 2) }} ₽
                                        </div>
                                        <div class="text-muted" style="font-size:.75rem;line-height:1.5">
                                            {{ number_format($productItems->sum(fn($i) => $i->calculateMasterPay()), 2) }} ₽
                                        </div>
                                    </th>
                                </tr>
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
                              action="{{ route('workshops.update-item-coeff', $workshop) }}"
                              id="coeffEditForm"
                              data-submit-guard>
                            @csrf
                            <div style="overflow-x:auto">
                                <table class="table table-sm mb-2" style="min-width:440px">
                                    <thead class="table-light">
                                    <tr>
                                        <th>Товар</th>
                                        <th style="width:1%"></th>
                                        <th class="text-center" style="width:130px">Базовый коэф.</th>
                                        <th class="text-center" style="width:180px">Правила</th>
                                        <th class="text-end" style="width:90px">Итог</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($productItems->values() as $i => $item)
                                        <tr>
                                            <td class="small">{{ $item->product->name ?? '—' }}</td>
                                            {{-- Метка роли: верхнее поле — пильщик, нижнее — мастер --}}
                                            <td class="text-muted">
                                                <div class="d-flex align-items-center" style="height:calc(1.5em + .5rem + 2px)" title="Пильщик"><i class="bi bi-person"></i></div>
                                                <div class="d-flex align-items-center mt-1" style="height:calc(1.5em + .5rem + 2px)" title="Мастер"><i class="bi bi-person-gear"></i></div>
                                            </td>
                                            <td class="text-center">
                                                <input type="hidden" name="items[{{ $i }}][item_id]" value="{{ $item->id }}">
                                                <input type="number"
                                                       name="items[{{ $i }}][base_coeff]"
                                                       class="form-control form-control-sm text-center coeff-base-input"
                                                       step="0.0001"
                                                       value="{{ number_format($item->base_coeff, 4, '.', '') }}"
                                                       data-row="{{ $i }}"
                                                       title="Пильщик">
                                                <input type="number"
                                                       name="items[{{ $i }}][master_base_coeff]"
                                                       class="form-control form-control-sm text-center coeff-master-base-input mt-1"
                                                       step="0.0001"
                                                       value="{{ number_format($item->master_base_cost_coeff ?? $item->product?->master_cost_coeff ?? 0, 4, '.', '') }}"
                                                       data-row="{{ $i }}"
                                                       title="Мастер">
                                            </td>
                                            <td>
                                                <div class="modifier-picker d-flex align-items-center gap-2 flex-wrap"
                                                     data-row="{{ $i }}"
                                                     data-tpl-index="{{ $i }}"
                                                     data-scope="workshop"
                                                     data-department-id="{{ $workshop->effectiveDepartmentId() }}"
                                                     data-sku="{{ $item->product?->sku }}"
                                                     data-input-name="items[{{ $i }}]"
                                                     data-active-keys="{{ $item->modifiers->pluck('key')->implode(',') }}"></div>
                                            </td>
                                            <td class="text-end">
                                                <div class="d-flex align-items-center justify-content-end" style="height:calc(1.5em + .5rem + 2px)">
                                                    <span class="badge bg-secondary coeff-result-display" data-row="{{ $i }}" title="Пильщик">
                                                        {{ number_format($item->effective_cost_coeff ?? 0, 4) }}
                                                    </span>
                                                </div>
                                                <div class="d-flex align-items-center justify-content-end mt-1" style="height:calc(1.5em + .5rem + 2px)">
                                                    <span class="badge bg-secondary coeff-master-result-display" data-row="{{ $i }}" title="Мастер">
                                                        {{ number_format($item->master_effective_cost_coeff ?? 0, 4) }}
                                                    </span>
                                                </div>
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
                        <i class="bi bi-check2-circle fs-3 text-muted d-block mb-1"></i>
                        <p class="text-muted small mb-0">Продукт не добавлен</p>
                    </div>
                @endif
            </div>

            {{-- Лог изменений --}}
            @if($workshop->workshopLogs->isNotEmpty())
                <div class="card shadow-sm mb-3">
                    <div class="card-header bg-white py-2">
                        <span class="small fw-semibold text-muted"><i class="bi bi-clock-history me-1"></i> История изменений</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Дата</th>
                                    <th>Тип</th>
                                    <th>Продукт Δ</th>
                                    <th>Позиции Δ</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach($workshop->workshopLogs as $log)
                                <tr class="{{ $log->type === 'updated' ? 'table-warning' : '' }}">
                                    <td class="text-nowrap">{{ $log->created_at->format('d.m H:i') }}</td>
                                    <td>
                                        @if($log->type === 'created')
                                            <span class="badge bg-success">Создание</span>
                                        @else
                                            <span class="badge bg-warning text-dark">Правка</span>
                                        @endif
                                    </td>
                                    <td>
                                        @php $d = (float) $log->package_quantity_delta; @endphp
                                        @if(abs($d) > 0.0001)
                                            <span class="fw-semibold {{ $d >= 0 ? 'text-success' : 'text-danger' }}">{{ $d >= 0 ? '+' : '' }}{{ number_format($d, 0) }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>
                                        @php $roleLabels = ['raw' => 'Сырьё', 'package' => 'Упаковка', 'product' => 'Продукт']; @endphp
                                        @foreach($log->items as $i)
                                            @php $delta = (float) $i->quantity_delta; @endphp
                                            <div class="small">
                                                <span class="text-muted">[{{ $roleLabels[$i->role] ?? '' }}]</span>
                                                {{ $i->product?->name ?? '?' }}:
                                                <span class="fw-semibold {{ $delta >= 0 ? 'text-success' : 'text-danger' }}">{{ $delta >= 0 ? '+' : '' }}{{ number_format($delta, 3) }}</span>
                                            </div>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

@push('scripts')
@include('partials.production-rates-js')
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

    function recalcRow(rowIdx) {
        const baseInput = document.querySelector(`.coeff-base-input[data-row="${rowIdx}"]`);
        const picker    = document.querySelector(`.modifier-picker[data-row="${rowIdx}"]`);
        const display   = document.querySelector(`.coeff-result-display[data-row="${rowIdx}"]`);
        if (!baseInput || !display) return;

        const base = parseFloat(baseInput.value) || 0;

        // Ровно то же, что считает сервер: база плюс сумма сработавших правил.
        // База хранится отдельно от результата, поэтому SKU-правила применяются
        // здесь так же, как в WorkshopService::updateItemCoeff.
        const ctx = {
            departmentId: picker?.dataset.departmentId || null,
            scope: 'workshop',
            sku: picker?.dataset.sku || null,
            manualKeys: picker ? ModifierPicker.checkedKeys(picker) : [],
        };
        const effective = RateFormula.effectiveCoeff({ ...ctx, baseCoeff: base });

        display.textContent = RateFormula.formatCoeff(effective);
        display.className   = display.className.replace(/bg-\w+/, effective < base ? 'bg-warning' : 'bg-secondary');

        const masterInput   = document.querySelector(`.coeff-master-base-input[data-row="${rowIdx}"]`);
        const masterDisplay = document.querySelector(`.coeff-master-result-display[data-row="${rowIdx}"]`);
        if (!masterInput || !masterDisplay) return;

        const masterBase = parseFloat(masterInput.value) || 0;
        const masterEff  = RateFormula.masterEffectiveCoeff({ ...ctx, baseCoeff: masterBase });

        masterDisplay.textContent = RateFormula.formatCoeff(masterEff);
        masterDisplay.className   = masterDisplay.className.replace(/bg-\w+/, masterEff < masterBase ? 'bg-warning' : 'bg-secondary');
    }

    document.querySelectorAll('.coeff-base-input, .coeff-master-base-input').forEach(el => {
        el.addEventListener('input', () => recalcRow(el.dataset.row));
    });
    document.addEventListener('change', function (e) {
        if (!e.target.classList.contains('modifier-checkbox')) return;
        const picker = e.target.closest('.modifier-picker');
        if (picker) recalcRow(picker.dataset.row);
    });

    document.querySelectorAll('.coeff-base-input').forEach(el => recalcRow(el.dataset.row));
});
</script>
@endpush
