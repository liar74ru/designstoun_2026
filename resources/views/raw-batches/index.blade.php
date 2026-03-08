@extends('layouts.app')

@section('title', 'Партии сырья')

@section('content')
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2 mb-0">📦 Партии сырья</h1>
            <a href="{{ route('raw-batches.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Новая партия
            </a>
        </div>

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

        <!-- Фильтры -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Статус</label>
                        <select name="filter[status]" class="form-select">
                            <option value="">Все</option>
                            @foreach($statuses as $value => $label)
                                <option value="{{ $value }}" {{ request('filter.status') == $value ? 'selected' : '' }}>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Пильщик</label>
                        <select name="filter[current_worker_id]" class="form-select"> <!-- Изменено здесь -->
                            <option value="">Все</option>
                            @foreach($workers as $worker)
                                <option value="{{ $worker->id }}" {{ request('filter.current_worker_id') == $worker->id ? 'selected' : '' }}>
                                    {{ $worker->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Сырьё</label>
                        <select name="filter[product_id]" class="form-select">
                            <option value="">Все</option>
                            @foreach($products as $product)
                                <option value="{{ $product->id }}" {{ request('filter.product_id') == $product->id ? 'selected' : '' }}>
                                    {{ $product->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Группа товаров</label>
                        <x-group-filter
                            :groups="$groupsTree"
                            :activeGroupId="request('filter.group_id')"
                            formId="filterForm"
                            inputName="filter[group_id]"
                        />
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Поиск по номеру</label>
                        <input type="text" name="filter[batch_number]" class="form-control" value="{{ request('filter.batch_number') }}"> <!-- Изменено здесь -->
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">Применить</button>
                        <a href="{{ route('raw-batches.index') }}" class="btn btn-outline-secondary">Сбросить</a>
                    </div>
                </form>
            </div>
        </div>

        @if($batches->count() > 0)
            <div class="card shadow-sm">
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
                                <td>{{ $batch->currentStore->name ?? '—' }}</td>
                                <td>{{ $batch->currentWorker->name ?? '—' }}</td>
                                <td>{{ $batch->created_at->format('d.m.Y H:i') }}</td>
                                <td>
                                    <a href="{{ route('raw-batches.show', $batch) }}" class="btn btn-sm btn-outline-info" title="Просмотр">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    @if($batch->status !== 'archived')
                                        <a href="{{ route('raw-batches.adjust.form', $batch) }}" class="btn btn-sm btn-outline-success" title="Скорректировать количество">
                                            <i class="bi bi-plus-slash-minus"></i>
                                        </a>
                                    @endif
                                    {{-- Копировать партию (создать новую на основе этой) --}}
                                    <a href="{{ route('raw-batches.copy', $batch) }}" class="btn btn-sm btn-outline-primary" title="Создать копию">
                                        <i class="bi bi-copy"></i>
                                    </a>
                                    @if($batch->status === 'active')
                                        <a href="{{ route('raw-batches.transfer.form', $batch) }}" class="btn btn-sm btn-outline-warning" title="Передать пильщику">
                                            <i class="bi bi-arrow-left-right"></i>
                                        </a>
                                        <a href="{{ route('raw-batches.return.form', $batch) }}" class="btn btn-sm btn-outline-secondary" title="Вернуть на склад">
                                            <i class="bi bi-arrow-return-left"></i>
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="d-flex justify-content-center mt-4">
                {{ $batches->withQueryString()->links() }}
            </div>
        @else
            <div class="text-center py-5">
                <i class="bi bi-inbox display-1 text-muted"></i>
                <h3 class="text-muted mt-3">Партии не найдены</h3>
                <p class="mb-4">Создайте первую партию сырья</p>
                <a href="{{ route('raw-batches.create') }}" class="btn btn-primary btn-lg">
                    <i class="bi bi-plus-circle"></i> Новая партия
                </a>
            </div>
        @endif
    </div>
@endsection
