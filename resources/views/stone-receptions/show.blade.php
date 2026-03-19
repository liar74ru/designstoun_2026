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
                    <div class="card-header bg-white">
                        <h5 class="mb-0">📦 Принятая продукция</h5>
                    </div>
                    <div class="card-body p-0">
                        @if($stoneReception->items->count() > 0)
                            <table class="table table-sm mb-0">
                                <thead class="table-light">
                                <tr>
                                    <th>Продукт</th>
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
                                    <th>Итого:</th>
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
                                        <th colspan="2" class="text-muted small fw-normal">
                                            Коэффициент выхода:
                                            {{ number_format($coeff * 100, 1) }}%
                                            (из 1 м³ сырья → {{ number_format($coeff, 3) }} м² плитки)
                                        </th>
                                    </tr>
                                @endif
                                </tfoot>
                            </table>
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
