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

    @include('partials.filters', [
        'filterCutters'     => $filterCutters,
        'cutterParam'       => 'current_worker_id',
        'filterRawProducts' => $filterRawProducts,
        'rawProductParam'   => 'product_id',
        'filterProducts'    => null,
        'showStatus'        => 'single',
        'statusOptions'     => $statuses,
    ])

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
                                    @if($batch->status === \App\Models\RawMaterialBatch::STATUS_IN_WORK)
                                        <form method="POST" action="{{ route('raw-batches.mark-used', $batch) }}" class="d-inline"
                                              onsubmit="return confirm('Отметить как «Израсходована»?')">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-warning" title="Израсходована">
                                                <i class="bi bi-check2-circle"></i>
                                            </button>
                                        </form>
                                    @endif
                                    @if($batch->status === \App\Models\RawMaterialBatch::STATUS_USED)
                                        <form method="POST" action="{{ route('raw-batches.mark-in-work', $batch) }}" class="d-inline"
                                              onsubmit="return confirm('Вернуть в работу?')">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-success" title="Вернуть в работу">
                                                <i class="bi bi-arrow-counterclockwise"></i>
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
                            @if($batch->status === \App\Models\RawMaterialBatch::STATUS_IN_WORK)
                                <form method="POST" action="{{ route('raw-batches.mark-used', $batch) }}"
                                      onsubmit="return confirm('Отметить как «Израсходована»?')">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-warning" style="min-width:90px">
                                        <i class="bi bi-check2-circle"></i> Израсх.
                                    </button>
                                </form>
                            @endif
                            @if($batch->status === \App\Models\RawMaterialBatch::STATUS_USED)
                                <form method="POST" action="{{ route('raw-batches.mark-in-work', $batch) }}"
                                      onsubmit="return confirm('Вернуть в работу?')">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-success" style="min-width:90px">
                                        <i class="bi bi-arrow-counterclockwise"></i> В работу
                                    </button>
                                </form>
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

@endsection
