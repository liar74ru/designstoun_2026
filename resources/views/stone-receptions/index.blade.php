@extends('layouts.app')

@section('title', 'Приемки камня')

@section('content')
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2 mb-0">📦 Приемки камня</h1>

            <a href="{{ route('stone-receptions.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Новая приемка
            </a>
        </div>

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill"></i> {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if($receptions->count() > 0)
            <div class="card shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Дата</th>
                            <th>Продукция</th>
                            <th>Всего</th>
                            <th>Сырье</th>
                            <th>Расход</th>
                            <th>Приемщик</th>
                            <th>Пильщик</th>
                            <th>Склад</th>
                            <th>Действия</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($receptions as $reception)
                            <tr>
                                <td>{{ $reception->id }}</td>
                                <td>{{ $reception->created_at->format('d.m.Y H:i') }}</td>
                                <td>
                                    @foreach($reception->items as $item)
                                        <div class="mb-1">
                                            <a href="{{ route('products.show', $item->product->moysklad_id) }}">
                                                <strong>{{ $item->product->name }}</strong>
                                            </a>
                                            <br>
                                            <small class="text-muted">{{ $item->product->sku }}</small>
                                            <span class="badge bg-info ms-2">{{ number_format($item->quantity, 3) }} м²</span>
                                        </div>
                                        @if(!$loop->last)
                                            <hr class="my-1">
                                        @endif
                                    @endforeach
                                </td>
                                <td>
                                    <span class="badge bg-primary">{{ number_format($reception->total_quantity, 3) }} м²</span>
                                </td>
                                <td>
                                    @if($reception->rawMaterialBatch)
                                        <a href="{{ route('raw-batches.show', $reception->rawMaterialBatch) }}">
                                            {{ $reception->rawMaterialBatch->product->name }}
                                        </a>
                                        <br>
                                        <small class="text-muted">Партия #{{ $reception->rawMaterialBatch->id }}</small>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge bg-warning">{{ number_format($reception->raw_quantity_used, 3) }} м³</span>
                                </td>
                                <td>{{ $reception->receiver->name }}</td>
                                <td>{{ $reception->cutter->name ?? '—' }}</td>
                                <td>{{ $reception->store->name ?? '—' }}</td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <a href="{{ route('stone-receptions.edit', $reception) }}"
                                           class="btn btn-sm btn-outline-primary"
                                           title="Редактировать">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="{{ route('stone-receptions.show', $reception) }}"
                                           class="btn btn-sm btn-outline-info"
                                           title="Просмотр">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <form action="{{ route('stone-receptions.destroy', $reception) }}"
                                              method="POST"
                                              class="d-inline"
                                              onsubmit="return confirm('Удалить приемку? Это также удалит все позиции продукции.')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Удалить">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="d-flex justify-content-center mt-4">
                {{ $receptions->links() }}
            </div>
        @else
            <div class="text-center py-5">
                <i class="bi bi-inbox display-1 text-muted"></i>
                <h3 class="text-muted mt-3">Нет приемок</h3>
                <p class="mb-4">Создайте первую приемку камня</p>
                <a href="{{ route('stone-receptions.create') }}" class="btn btn-primary btn-lg">
                    <i class="bi bi-plus-circle"></i> Новая приемка
                </a>
            </div>
        @endif
    </div>
@endsection
