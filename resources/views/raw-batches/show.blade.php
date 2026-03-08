@extends('layouts.app')

@section('title', 'Партия #' . $batch->id)

@section('content')
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2 mb-0">📄 Партия #{{ $batch->id }}</h1>
            <a href="{{ route('raw-batches.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> К списку
            </a>
        </div>

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
                                <td>
                                    @if($batch->status === 'active')
                                        <span class="badge bg-success">Активна</span>
                                    @elseif($batch->status === 'used')
                                        <span class="badge bg-warning text-dark">Израсходована</span>
                                    @elseif($batch->status === 'archived')
                                        <span class="badge bg-dark">Архив</span>
                                    @else
                                        <span class="badge bg-secondary">Возвращена</span>
                                    @endif
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

                @if($batch->status !== 'archived')
                    <div class="card shadow-sm mb-4">
                        <div class="card-body">
                            <div class="d-flex flex-wrap gap-2">

                                {{-- Корректировка количества — для любой не-архивной партии --}}
                                <a href="{{ route('raw-batches.adjust.form', $batch) }}" class="btn btn-outline-primary">
                                    <i class="bi bi-plus-slash-minus"></i> Скорректировать количество
                                </a>

                                @if($batch->status === 'active')
                                    <a href="{{ route('raw-batches.transfer.form', $batch) }}" class="btn btn-warning">
                                        <i class="bi bi-arrow-left-right"></i> Передать пильщику
                                    </a>
                                    <a href="{{ route('raw-batches.return.form', $batch) }}" class="btn btn-secondary">
                                        <i class="bi bi-arrow-return-left"></i> Вернуть на склад
                                    </a>
                                @endif

                                {{-- Архив: только для used/returned с нулевым остатком --}}
                                @if($batch->canBeArchived())
                                    <form method="POST" action="{{ route('raw-batches.archive', $batch) }}"
                                          onsubmit="return confirm('Отправить партию в архив? Это финальный статус, редактирование будет недоступно.')">
                                        @csrf
                                        <button type="submit" class="btn btn-dark">
                                            <i class="bi bi-archive"></i> В архив
                                        </button>
                                    </form>
                                @elseif(in_array($batch->status, ['used', 'returned']) && (float)$batch->remaining_quantity > 0)
                                    <button class="btn btn-dark" disabled title="Сначала спишите остаток ({{ number_format($batch->remaining_quantity, 3) }} м³)">
                                        <i class="bi bi-archive"></i> В архив (остаток не нулевой)
                                    </button>
                                @endif

                            </div>
                        </div>
                    </div>
                @else
                    <div class="alert alert-secondary">
                        <i class="bi bi-archive me-2"></i>
                        <strong>Партия в архиве.</strong> Редактирование недоступно.
                    </div>
                @endif
            </div>

            <!-- Правая колонка: история и готовая продукция -->
            <div class="col-md-6">
                <!-- Блок с готовой продукцией (то, что нам нужно) -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">📦 Готовая продукция из этой партии</h5>
                    </div>
                    <div class="card-body p-0">
                        @if($batch->receptions && $batch->receptions->count() > 0)
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                    <tr>
                                        <th>Продукт</th>
                                        <th>Кол-во</th>
                                        <th>Расход сырья</th>
                                        <th>Дата</th>
                                        <th>Приемщик</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($batch->receptions as $reception)
                                        <tr>
                                            <td>
                                                {{-- Теперь у приёмки много продуктов через items --}}
                                                @forelse($reception->items as $item)
                                                    @if($item->product)
                                                        <a href="{{ route('products.show', $item->product->moysklad_id) }}">
                                                            {{ $item->product->name }}
                                                        </a>
                                                        <span class="badge bg-primary ms-1">{{ number_format($item->quantity, 3) }} м²</span><br>
                                                    @endif
                                                @empty
                                                    <span class="text-muted">—</span>
                                                @endforelse
                                            </td>
                                            <td>
                                                {{-- Итого по всем позициям --}}
                                                <span class="badge bg-primary">{{ number_format($reception->items->sum('quantity'), 3) }} м²</span>
                                            </td>
                                            <td>
                                                <span class="badge bg-info">{{ number_format($reception->raw_quantity_used, 3) }} м²</span>
                                            </td>
                                            <td>{{ $reception->created_at->format('d.m.Y H:i') }}</td>
                                            <td>{{ $reception->receiver->name ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                    <tfoot class="table-light">
                                    @php
                                        $totalQty = $batch->receptions->sum(fn($r) => $r->items->sum('quantity'));
                                        $totalRaw = $batch->receptions->sum('raw_quantity_used');
                                    @endphp
                                    <tr>
                                        <th>Итого:</th>
                                        <th>{{ number_format($totalQty, 3) }} м²</th>
                                        <th>{{ number_format($totalRaw, 3) }} м²</th>
                                        <th colspan="2"></th>
                                    </tr>
                                    <tr>
                                        <th colspan="5" class="text-muted">
                                            Коэффициент выхода:
                                            @if($batch->initial_quantity > 0)
                                                {{ number_format(($totalQty / $batch->initial_quantity) * 100, 1) }}%
                                                (из 1 м² сырья получается {{ number_format($totalQty / $batch->initial_quantity, 3) }} м² плитки)
                                            @else
                                                —
                                            @endif
                                        </th>
                                    </tr>
                                    </tfoot>
                                </table>
                            </div>
                        @else
                            <div class="text-center py-4">
                                <i class="bi bi-box-seam display-4 text-muted"></i>
                                <p class="text-muted mt-3">Из этой партии ещё не произведена готовая продукция</p>
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
