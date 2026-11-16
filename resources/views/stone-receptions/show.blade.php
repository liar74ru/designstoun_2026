@extends('layouts.app')

@section('title', 'Приёмка #' . $stoneReception->id)

@section('content')
    <div class="container py-3 py-md-4">

        <x-page-header
            title="🪨 Приёмка #{{ $stoneReception->id }}"
            back-url="{{ route('stone-receptions.index') }}"
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

                        {{-- Склад приёмки --}}
                        <div class="info-block">
                            <div class="info-block-header">
                                <span class="small fw-semibold text-muted">Склад приёмки</span>
                            </div>
                            <div class="info-block-body">
                                @if($stoneReception->status === \App\Models\StoneReception::STATUS_ACTIVE
                                    && auth()->user()->can('modify', $stoneReception))
                                    <form data-submit-guard method="POST"
                                          action="{{ route('stone-receptions.update-store', $stoneReception) }}"
                                          class="d-flex gap-2 align-items-center">
                                        @csrf
                                        @method('PATCH')
                                        <select name="store_id"
                                                class="form-select form-select-sm"
                                                style="font-size:.8rem;padding:.18rem .35rem;border-radius:.4rem"
                                                required>
                                            <option value="">— Выберите склад —</option>
                                            @foreach($stores as $store)
                                                <option value="{{ $store->id }}"
                                                    {{ $stoneReception->store_id == $store->id ? 'selected' : '' }}>
                                                    {{ $store->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-primary" style="flex-shrink:0">
                                            <i class="bi bi-save"></i>
                                        </button>
                                    </form>
                                @else
                                    <span class="small">{{ $stoneReception->store?->name ?? '—' }}</span>
                                @endif
                            </div>
                        </div>

                        {{-- Отдел --}}
                        <div class="info-block">
                            <div class="info-block-header">
                                <span class="small fw-semibold text-muted">Отдел</span>
                            </div>
                            <div class="info-block-body">
                                @if(auth()->user()->isAdmin())
                                    <form data-submit-guard method="POST"
                                          action="{{ route('stone-receptions.update-department', $stoneReception) }}"
                                          class="d-flex gap-2 align-items-center">
                                        @csrf
                                        @method('PATCH')
                                        <select name="department_id"
                                                class="form-select form-select-sm"
                                                style="font-size:.8rem;padding:.18rem .35rem;border-radius:.4rem"
                                                required>
                                            @foreach($departments as $department)
                                                <option value="{{ $department->id }}"
                                                    {{ $stoneReception->department_id == $department->id ? 'selected' : '' }}>
                                                    {{ $department->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-primary" style="flex-shrink:0">
                                            <i class="bi bi-save"></i>
                                        </button>
                                    </form>
                                @else
                                    <span class="small">{{ $stoneReception->department?->name ?? '—' }}</span>
                                @endif
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
                            :show-button="auth()->user()->can('modify', $stoneReception)"
                            wrapper="info-block" />

                {{-- Кнопки действий --}}
                @can('modify', $stoneReception)
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
                                <form data-submit-guard method="POST"
                                      action="{{ route('stone-receptions.mark-completed', $stoneReception) }}"
                                      data-confirm="Отметить приёмку как «Завершена»?&#10;Сырьё в партии закончилось.">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-warning w-100">
                                        <i class="bi bi-check2-circle"></i> Завершена
                                        <span class="opacity-75 small">(сырьё израсходовано)</span>
                                    </button>
                                </form>
                            @endif

                            @if($stoneReception->status !== \App\Models\StoneReception::STATUS_ACTIVE)
                                <form data-submit-guard method="POST"
                                      action="{{ route('stone-receptions.reset-status', $stoneReception) }}"
                                      data-confirm="Вернуть приёмку в статус «Активна»?">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-success w-100">
                                        <i class="bi bi-arrow-counterclockwise"></i> Вернуть в работу
                                    </button>
                                </form>
                            @endif

                            <form data-submit-guard method="POST"
                                  action="{{ route('stone-receptions.destroy', $stoneReception) }}"
                                  data-confirm="Удалить приёмку #{{ $stoneReception->id }}? Остатки будут возвращены в партию.">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger w-100">
                                    <i class="bi bi-trash"></i> Удалить
                                </button>
                            </form>

                        </div>
                    </div>
                </div>
                @endcan

            </div>

            {{-- Правая колонка: продукты и журнал --}}
            <div class="col-md-6">

                {{-- Продукция и правка коэффициентов — общий блок с цехом --}}
                @php
                    $yieldText = null;
                    if ($stoneReception->raw_quantity_used > 0) {
                        $yieldCoeff = $stoneReception->items->sum('quantity') / $stoneReception->raw_quantity_used;
                        $yieldText  = 'Коэф. выхода: ' . number_format($yieldCoeff * 100, 1) . '% (1 '
                            . \App\Support\Uom::of($stoneReception->rawMaterialBatch?->product) . ' → '
                            . \App\Support\Uom::format($yieldCoeff, \App\Support\Uom::common($stoneReception->items->map(fn ($i) => $i->product?->uom))) . ')';
                    }
                @endphp
                @include('partials.production-document.products', [
                    'document'     => $stoneReception,
                    'items'        => $stoneReception->items,
                    'scope'        => 'reception',
                    'batchSku'     => $stoneReception->rawMaterialBatch?->product?->sku,
                    'refreshRoute' => route('stone-receptions.refresh-item-coeffs', $stoneReception),
                    'refreshRulesRoute' => route('stone-receptions.refresh-item-rules', $stoneReception),
                    'rulesOutdated' => $rulesOutdated ?? false,
                    'updateRoute'  => route('stone-receptions.update-item-coeff', $stoneReception),
                    'title'        => 'Принятая продукция',
                    'icon'         => 'bi-box-seam',
                    'emptyText'    => 'Продукты не добавлены',
                    'yieldText'    => $yieldText,
                ])

                {{-- Расходы производства (только для администратора) --}}
                @if(auth()->user()->is_admin)
                @php
                    $costTotalQty   = $stoneReception->items->sum('quantity');
                    $costWorkerPay  = $stoneReception->items->sum(fn($i) => $i->calculateWorkerPay());
                    $costMasterPay  = $stoneReception->items->sum(fn($i) => $i->calculateMasterPay());
                    // Накладные считаем по отделу приёмки — тем же правилом, что уходит в МойСклад
                    $costPerUnit    = \App\Support\DepartmentSettings::overheadPerUnit($stoneReception->effectiveDepartmentId());
                    $costReception  = round($costPerUnit * $costTotalQty);
                    $costOther      = 0;
                    $costGrandTotal = $costWorkerPay + $costMasterPay + $costReception + $costOther;
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
                            <td>Зарплата мастеру</td>
                            <td class="text-end text-nowrap fw-semibold">{{ number_format($costMasterPay, 0, '.', ' ') }} ₽</td>
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
                                    'log'              => $log,
                                    'showActions'      => false,
                                    'showRawDetails'   => true,
                                    'showStoreBottom'  => false,
                                    'isMaster'         => false,
                                    'editableReceiver' => auth()->user()->isAdmin(),
                                    'masterWorkers'    => $masterWorkers,
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
@include('partials.production-rates-js')
@if(auth()->user()->isAdmin())
    @include('partials.reception-log-receiver-script')
@endif
@include('partials.production-document.coeff-edit-script')
@endpush
