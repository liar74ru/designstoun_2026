@extends('layouts.app')
@section('title', 'Цех #' . $workshop->id)

@section('content')
<div class="container py-3 py-md-4" style="max-width:1100px">

    <x-page-header title="Цех #{{ $workshop->id }}" :back-url="route('workshops.index')" back-label="К списку" mobileTitle="Цех" />

    @include('partials.alerts')

    @php
        // Количество — до 4 знаков без хвостовых нулей: «1,2», «0,1255»
        $fmtQty = fn ($v) => rtrim(rtrim(number_format((float) $v, 4), '0'), '.');

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
                    @if($workshop->status === 'active' && auth()->user()->can('modify', $workshop))
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
                        <span class="small text-muted">Автоматически (работник + мастер + накладные)</span>
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
                :show-button="auth()->user()->can('modify', $workshop)"
                wrapper="info-block" />

            {{-- Кнопки действий --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white py-2">
                    <span class="fw-semibold small text-muted">Возможные действия</span>
                </div>
                <div class="card-body py-2">
                    <div class="d-grid gap-2">

                        @can('modify', $workshop)
                        @if($workshop->status === 'active')
                            <a href="{{ route('workshops.edit', $workshop) }}" class="btn btn-success">
                                <i class="bi bi-pencil"></i> Редактировать
                            </a>

                            <form method="POST"
                                  action="{{ route('workshops.mark-completed', $workshop) }}"
                                  data-confirm="Закрыть операцию?"
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
                                  data-confirm="Сбросить статус?"
                                  data-submit-guard>
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="bi bi-arrow-counterclockwise"></i> Активировать
                                </button>
                            </form>
                        @endif
                        @endcan

                        <form method="POST" action="{{ route('workshops.copy', $workshop) }}" data-submit-guard>
                            @csrf
                            <button type="submit" class="btn btn-outline-info w-100">
                                <i class="bi bi-copy"></i> Копировать
                            </button>
                        </form>

                        @can('modify', $workshop)
                        <form method="POST"
                              action="{{ route('workshops.destroy', $workshop) }}"
                              data-confirm="Удалить операцию Цех #{{ $workshop->id }}? Тара будет возвращена на склад."
                              data-submit-guard>
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger w-100">
                                <i class="bi bi-trash"></i> Удалить
                            </button>
                        </form>
                        @endcan

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
                    <span class="text-muted small">Итого: <strong>{{ $fmtQty($rawItems->sum('quantity')) }}</strong></span>
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
                                <td class="text-end">{{ $fmtQty($item->quantity) }}</td>
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
                                <td class="text-end">{{ $fmtQty($item->quantity) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="text-muted text-center">—</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Продукция и правка коэффициентов — общий блок с приёмкой --}}
            @include('partials.production-document.products', [
                'document'     => $workshop,
                'items'        => $productItems,
                'scope'        => 'workshop',
                'refreshRoute' => route('workshops.refresh-item-coeffs', $workshop),
                'refreshRulesRoute' => route('workshops.refresh-item-rules', $workshop),
                'rulesOutdated' => $rulesOutdated ?? false,
                'updateRoute'  => route('workshops.update-item-coeff', $workshop),
                'title'        => 'Продукт',
                'icon'         => 'bi-check2-circle',
                'emptyText'    => 'Продукт не добавлен',
                'qtyFormat'    => fn ($qty, $uom) => trim($fmtQty($qty) . ' ' . \App\Support\Uom::label($uom)),
            ])

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
                                                <span class="fw-semibold {{ $delta >= 0 ? 'text-success' : 'text-danger' }}">{{ $delta >= 0 ? '+' : '' }}{{ $fmtQty($delta) }}</span>
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
@include('partials.production-document.coeff-edit-script')
@endpush
