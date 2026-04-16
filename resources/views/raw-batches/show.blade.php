@extends('layouts.app')

@section('title', 'Партия #' . $batch->id)

@section('content')
    <div class="container py-4">
        <x-page-header
            title="📄 Партия #{{ $batch->batch_number ?? $batch->id }}"
            mobileTitle="Партия #{{ $batch->batch_number ?? $batch->id }}"
            backUrl="{{ $backUrl }}"
            backLabel="К списку">
        </x-page-header>

        @include('partials.alerts')

        <div class="row">
            <!-- Левая колонка: информация о партии -->
            <div class="col-md-6">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">Основная информация</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr>
                                <th style="width: 150px;">Номер партии:</th>
                                <td>{{ $batch->batch_number ?? '—' }}</td>
                            </tr>
                            <tr>
                                <th>Продукт (сырьё):</th>
                                <td>
                                    @if($batch->product)
                                        <a href="{{ route('products.show', $batch->product->moysklad_id) }}">
                                            <strong>{{ $batch->product->name }}</strong>
                                        </a>
                                        <br>
                                        <small class="text-muted">Артикул: {{ $batch->product->sku }}</small>
                                    @else
                                        <span class="text-danger">Продукт не найден</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>Изначальное кол-во:</th>
                                <td>{{ number_format($batch->initial_quantity, 3) }} м²</td>
                            </tr>
                            <tr>
                                <th>Остаток сырья:</th>
                                <td>
                                <span class="badge {{ $batch->remaining_quantity > 0 ? 'bg-primary' : 'bg-secondary' }}">
                                    {{ number_format($batch->remaining_quantity, 3) }} м²
                                </span>
                                </td>
                            </tr>
                            <tr>
                                <th>Статус:</th>
                                <td class="d-flex gap-1 flex-wrap">
                                    <span class="badge {{ $batch->statusBadgeClass() }}">
                                        {{ $batch->statusLabel() }}
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th>Текущий склад:</th>
                                <td>{{ $batch->currentStore->name ?? '—' }}</td>
                            </tr>
                            <tr>
                                <th>Закреплен за:</th>
                                <td>{{ $batch->currentWorker->name ?? '—' }}</td>
                            </tr>
                            <tr>
                                <th>Дата создания:</th>
                                <td>{{ $batch->created_at ? $batch->created_at->format('d.m.Y H:i:s') : '—' }}</td>
                            </tr>
                        </table>
                    </div>
                </div>

                {{-- МойСклад: статус синхронизации партии --}}
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small fw-semibold text-muted"><i class="bi bi-cloud me-1"></i>МойСклад</span>
                            @if($batch->moysklad_sync_status)
                                <span class="badge {{ $batch->syncStatusBadgeClass() }} small">
                                    {{ $batch->syncStatusLabel() }}
                                </span>
                            @endif
                        </div>
                    </div>
                    <div class="card-body py-2">
                        @if($batch->hasSyncError())
                            <div class="small text-warning-emphasis">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                <strong>Ошибка:</strong> {{ $batch->moysklad_sync_error }}
                            </div>
                        @elseif($batch->isSynced())
                            <div class="small text-success">
                                <i class="bi bi-check-circle me-1"></i>
                                Синхронизировано
                                @if($batch->moysklad_processing_name)
                                    · <span class="text-muted">{{ $batch->moysklad_processing_name }}</span>
                                @endif
                            </div>
                            @if(auth()->user()->is_admin)
                                <div class="text-muted mt-1" style="font-size:.72rem;word-break:break-all">
                                    <i class="bi bi-fingerprint me-1"></i>
                                    <code style="font-size:.7rem">{{ $batch->moysklad_processing_id }}</code>
                                </div>
                            @endif
                        @else
                            <div class="small text-muted">
                                <i class="bi bi-cloud-slash me-1"></i>Перемещение не создано
                            </div>
                        @endif
                        @if($batch->synced_at)
                            <div class="text-muted mt-2" style="font-size:.72rem">
                                <i class="bi bi-clock-history me-1"></i>
                                Последняя синхр.: {{ $batch->synced_at->format('d.m.Y H:i') }}
                            </div>
                        @endif
                    </div>
                </div>

                @if($batch->status !== 'archived')
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-white py-2">
                            <span class="fw-semibold small text-muted">Возможные действия</span>
                        </div>
                        <div class="card-body py-2">
                            <div class="d-grid gap-2">

                                <a href="{{ route('raw-batches.adjust.form', $batch) }}" class="btn btn-success">
                                    <i class="bi bi-plus-slash-minus"></i> Изменить количество
                                </a>

                                <form method="POST" action="{{ route('raw-batches.sync', $batch) }}">
                                    @csrf
                                    <button type="submit"
                                            class="btn w-100 {{ $batch->hasSyncError() ? 'btn-warning' : ($batch->hasMoySkladProcessing() ? 'btn-outline-secondary' : 'btn-outline-primary') }}">
                                        <i class="bi bi-arrow-repeat me-1"></i>
                                        {{ $batch->hasMoySkladProcessing() ? 'Синхронизировать с МойСклад' : 'Создать перемещение' }}
                                    </button>
                                </form>

                                <a href="{{ route('raw-batches.copy', $batch) }}" class="btn btn-primary">
                                    <i class="bi bi-copy"></i> Копировать
                                </a>

                                @if($batch->canBeTransferredOrReturned())
                                    <a href="{{ route('raw-batches.transfer.form', $batch) }}" class="btn btn-warning">
                                        <i class="bi bi-arrow-left-right"></i> Передать пильщику
                                    </a>
                                    <a href="{{ route('raw-batches.return.form', $batch) }}" class="btn btn-secondary">
                                        <i class="bi bi-arrow-return-left"></i> Вернуть на склад
                                    </a>
                                @endif

                                @if($batch->canBeMarkedAsUsed())
                                    <form method="POST" action="{{ route('raw-batches.mark-used', $batch) }}"
                                          onsubmit="return confirm('Отметить партию как «Израсходована»?\nСвязанная активная приёмка будет завершена.')">
                                        @csrf
                                        <button type="submit" class="btn btn-warning w-100">
                                            <i class="bi bi-check2-circle"></i> Израсходована
                                        </button>
                                    </form>
                                @endif

                                @if($batch->status === \App\Models\RawMaterialBatch::STATUS_USED)
                                    <form method="POST" action="{{ route('raw-batches.mark-in-work', $batch) }}"
                                          onsubmit="return confirm('Вернуть партию в статус «В работе»?\nЕсли есть завершённая приёмка, она снова станет активной.')">
                                        @csrf
                                        <button type="submit" class="btn btn-success w-100">
                                            <i class="bi bi-arrow-counterclockwise"></i> Вернуть в работу
                                        </button>
                                    </form>
                                @endif

                                @if($batch->canBeArchived())
                                    <form method="POST" action="{{ route('raw-batches.archive', $batch) }}"
                                          onsubmit="return confirm('Отправить партию в архив? Это финальный статус.')">
                                        @csrf
                                        <button type="submit" class="btn btn-dark w-100">
                                            <i class="bi bi-archive"></i> В архив
                                        </button>
                                    </form>
                                @elseif(in_array($batch->status, ['used', 'returned']) && (float)$batch->remaining_quantity > 0)
                                    <button class="btn btn-dark" disabled title="Сначала спишите остаток">
                                        <i class="bi bi-archive"></i> В архив
                                    </button>
                                @endif

                                @if($batch->canEditDetails())
                                    <a href="{{ route('raw-batches.edit', $batch) }}" class="btn btn-secondary">
                                        <i class="bi bi-pencil"></i> Изменить партию
                                    </a>
                                @endif

                                @if($batch->canBeEditedOrDeleted())
                                    <form method="POST" action="{{ route('raw-batches.destroy-new', $batch) }}"
                                          onsubmit="return confirm('Удалить партию #{{ $batch->id }}? Это действие необратимо.')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-danger w-100">
                                            <i class="bi bi-trash"></i> Удалить <span class="opacity-75 small">(Только для новой)</span>
                                        </button>
                                    </form>
                                @endif

                            </div>
                        </div>
                    </div>
                @else
                    <div class="alert alert-secondary py-2 small">
                        <i class="bi bi-archive me-2"></i>
                        <strong>Партия в архиве.</strong> Редактирование недоступно.
                    </div>
                @endif
            </div>

            <!-- Правая колонка: история и готовая продукция -->
            <div class="col-md-6">
                <!-- Блок с готовой продукцией (то, что нам нужно) -->
                @php
                    $totalQty = $batch->receptions ? $batch->receptions->sum(fn($r) => $r->items->sum('quantity')) : 0;
                    $totalRaw = $batch->receptions ? $batch->receptions->sum('raw_quantity_used') : 0;
                @endphp
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
                        <span class="fw-semibold small">📦 Готовая продукция из этой партии</span>
                        @if($batch->receptions && $batch->receptions->count() > 0)
                            <span class="badge bg-secondary">{{ $batch->receptions->count() }}</span>
                        @endif
                    </div>
                    <div class="card-body p-0">
                        @if($batch->receptions && $batch->receptions->count() > 0)

                            {{-- Десктоп: таблица --}}
                            <div class="d-none d-md-block">
                                <div class="table-responsive">
                                    <table class="table table-hover table-sm mb-0">
                                        <thead class="table-light">
                                        <tr>
                                            <th>Продукт</th>
                                            <th class="text-end">Кол-во</th>
                                            <th class="text-end">Расход</th>
                                            <th>Дата</th>
                                            <th>Приёмщик</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach($batch->receptions as $reception)
                                            <tr>
                                                <td>
                                                    @forelse($reception->items as $item)
                                                        @if($item->product)
                                                            <a href="{{ route('products.show', $item->product->moysklad_id) }}" class="small">
                                                                {{ $item->product->name }}
                                                            </a>
                                                            <span class="text-muted small ms-1">× {{ number_format($item->quantity, 3) }}</span><br>
                                                        @endif
                                                    @empty
                                                        <span class="text-muted small">—</span>
                                                    @endforelse
                                                </td>
                                                <td class="text-end">
                                                    <span class="badge bg-primary">{{ number_format($reception->items->sum('quantity'), 3) }} м²</span>
                                                </td>
                                                <td class="text-end">
                                                    <span class="badge bg-info">{{ number_format($reception->raw_quantity_used, 3) }} м²</span>
                                                </td>
                                                <td class="small text-nowrap">{{ $reception->created_at->format('d.m.Y H:i') }}</td>
                                                <td class="small">{{ $reception->receiver->name ?? '—' }}</td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                        <tfoot class="table-light">
                                        <tr>
                                            <th class="small">Итого:</th>
                                            <th class="text-end"><span class="badge bg-primary">{{ number_format($totalQty, 3) }} м²</span></th>
                                            <th class="text-end"><span class="badge bg-info">{{ number_format($totalRaw, 3) }} м²</span></th>
                                            <th colspan="2" class="text-muted small fw-normal">
                                                @if($batch->initial_quantity > 0)
                                                    Выход: {{ number_format(($totalQty / $batch->initial_quantity) * 100, 1) }}%
                                                @endif
                                            </th>
                                        </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>

                            {{-- Мобильный: карточки --}}
                            <div class="d-md-none" style="padding:.35rem .4rem">
                                @foreach($batch->receptions as $reception)
                                    @php
                                        $firstItem  = $reception->items->first();
                                        $skuColor   = \App\Models\Product::getColorBySku($firstItem?->product?->sku ?? null);
                                        $skuBg      = $skuColor === '#FFFFFF' ? '' : 'background:' . $skuColor . '18;';
                                    @endphp
                                    <div style="border-left:3px solid {{ $skuColor }};{{ $skuBg }}padding:.3rem .4rem;border-bottom:1px solid #f0f0f0;margin-bottom:.2rem;border-radius:.25rem">
                                        {{-- Дата + расход --}}
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <span class="text-muted" style="font-size:.72rem">{{ $reception->created_at->format('d.m.Y H:i') }}</span>
                                            <span class="badge bg-info" style="font-size:.65rem">сырьё: {{ number_format($reception->raw_quantity_used, 3) }} м²</span>
                                        </div>
                                        {{-- Позиции --}}
                                        @foreach($reception->items as $item)
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div class="small fw-semibold text-truncate me-2" style="font-size:.8rem;max-width:65%">
                                                    {{ $item->product?->name ?? '—' }}
                                                </div>
                                                <span class="badge bg-primary" style="font-size:.65rem">{{ number_format($item->quantity, 3) }} м²</span>
                                            </div>
                                        @endforeach
                                        {{-- Приёмщик --}}
                                        @if($reception->receiver)
                                            <div class="text-muted mt-1" style="font-size:.7rem">
                                                <i class="bi bi-person me-1"></i>{{ $reception->receiver->name }}
                                            </div>
                                        @endif
                                    </div>
                                @endforeach

                                {{-- Итого мобильный --}}
                                <div class="d-flex justify-content-between align-items-center mt-2 pt-1" style="border-top:1px solid #dee2e6">
                                    <span class="small fw-semibold">Итого:</span>
                                    <div class="d-flex gap-1">
                                        <span class="badge bg-primary" style="font-size:.68rem">{{ number_format($totalQty, 3) }} м²</span>
                                        @if($batch->initial_quantity > 0)
                                            <span class="badge bg-secondary" style="font-size:.68rem">
                                                выход {{ number_format(($totalQty / $batch->initial_quantity) * 100, 1) }}%
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </div>

                        @else
                            <div class="text-center py-4">
                                <i class="bi bi-box-seam display-4 text-muted"></i>
                                <p class="text-muted mt-3 small">Из этой партии ещё не произведена готовая продукция</p>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- История перемещений -->
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">История перемещений</h5>
                    </div>
                    <div class="card-body p-0">
                        @if($batch->movements && $batch->movements->count() > 0)
                            <div class="list-group list-group-flush">
                                @foreach($batch->movements as $movement)
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between">
                                            <strong>{{ $movement->created_at->format('d.m.Y H:i') }}</strong>
                                            <span class="badge bg-info">{{ number_format($movement->quantity, 3) }} м²</span>
                                        </div>
                                        <div class="small">
                                            @switch($movement->movement_type)
                                                @case('create')
                                                    <i class="bi bi-plus-circle text-success"></i>
                                                    Создание партии:
                                                    <strong>{{ $movement->fromStore->name ?? '?' }}</strong> →
                                                    <strong>{{ $movement->toStore->name ?? '?' }}</strong>
                                                    @if($movement->toWorker)
                                                        для {{ $movement->toWorker->name }}
                                                    @endif
                                                    @break
                                                @case('transfer_to_worker')
                                                    <i class="bi bi-arrow-left-right text-warning"></i>
                                                    Передача пильщику:
                                                    <strong>{{ $movement->fromWorker->name ?? '?' }}</strong> →
                                                    <strong>{{ $movement->toWorker->name ?? '?' }}</strong>
                                                    @break
                                                @case('return_to_store')
                                                    <i class="bi bi-arrow-return-left text-secondary"></i>
                                                    Возврат на склад:
                                                    <strong>{{ $movement->fromStore->name ?? '?' }}</strong> →
                                                    <strong>{{ $movement->toStore->name ?? '?' }}</strong>
                                                    @break
                                                @case('use')
                                                    <i class="bi bi-scissors text-primary"></i>
                                                    Списано при производстве:
                                                    <strong>{{ $movement->quantity }}</strong> м²
                                                    @break
                                            @endswitch
                                        </div>
                                        <div class="small text-muted">
                                            Оператор: {{ $movement->movedBy->name ?? 'Система' }}
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="text-center py-4">
                                <p class="text-muted mb-0">Нет перемещений</p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
