@extends('layouts.app')

@section('title', 'Поступление №' . $supplierOrder->number)

@section('content')
    <div class="container py-4">
        <x-page-header
            title="📄 Поступление №{{ $supplierOrder->number }}"
            mobileTitle="№{{ $supplierOrder->number }}"
            backUrl="{{ route('supplier-orders.index') }}"
            backLabel="К списку">
        </x-page-header>

        @include('partials.alerts')

        <div class="row">
            <!-- Левая колонка -->
            <div class="col-md-6">

                {{-- Основная информация --}}
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">Основная информация</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr>
                                <th style="width: 150px;">Номер:</th>
                                <td>{{ $supplierOrder->number }}</td>
                            </tr>
                            <tr>
                                <th>Поставщик:</th>
                                <td>{{ $supplierOrder->counterparty->name ?? '—' }}</td>
                            </tr>
                            <tr>
                                <th>Склад:</th>
                                <td>{{ $supplierOrder->store->name ?? '—' }}</td>
                            </tr>
                            <tr>
                                <th>Получатель:</th>
                                <td>{{ $supplierOrder->receiver->name ?? '—' }}</td>
                            </tr>
                            <tr>
                                <th>Статус:</th>
                                <td>
                                    <span class="badge {{ $supplierOrder->statusBadgeClass() }}">
                                        {{ $supplierOrder->statusLabel() }}
                                    </span>
                                </td>
                            </tr>
                            @if($supplierOrder->note)
                                <tr>
                                    <th>Примечание:</th>
                                    <td>{{ $supplierOrder->note }}</td>
                                </tr>
                            @endif
                            <tr>
                                <th>Дата создания:</th>
                                <td>{{ $supplierOrder->created_at?->format('d.m.Y H:i:s') ?? '—' }}</td>
                            </tr>
                        </table>
                    </div>
                </div>

                {{-- МойСклад --}}
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small fw-semibold text-muted"><i class="bi bi-cloud me-1"></i>МойСклад</span>
                            <span class="badge {{ $supplierOrder->statusBadgeClass() }} small">
                                {{ $supplierOrder->statusLabel() }}
                            </span>
                        </div>
                    </div>
                    <div class="card-body py-2">

                        {{-- Заказ поставщику --}}
                        <div class="mb-2">
                            <div class="small fw-semibold text-muted mb-1">Заказ поставщику</div>
                            @if($supplierOrder->moysklad_id)
                                <div class="small text-success">
                                    <i class="bi bi-check-circle me-1"></i>Создан
                                </div>
                                @if(auth()->user()->is_admin)
                                    <div class="text-muted mt-1" style="font-size:.72rem;word-break:break-all">
                                        <i class="bi bi-fingerprint me-1"></i>
                                        <code style="font-size:.7rem">{{ $supplierOrder->moysklad_id }}</code>
                                    </div>
                                @endif
                            @else
                                <div class="small text-muted">
                                    <i class="bi bi-cloud-slash me-1"></i>Не создан
                                </div>
                            @endif
                        </div>

                        {{-- Приёмка --}}
                        <div class="mb-2">
                            <div class="small fw-semibold text-muted mb-1">Приёмка</div>
                            @if($supplierOrder->supply_moysklad_id)
                                <div class="small text-success">
                                    <i class="bi bi-check-circle me-1"></i>Создана
                                </div>
                                @if(auth()->user()->is_admin)
                                    <div class="text-muted mt-1" style="font-size:.72rem;word-break:break-all">
                                        <i class="bi bi-fingerprint me-1"></i>
                                        <code style="font-size:.7rem">{{ $supplierOrder->supply_moysklad_id }}</code>
                                    </div>
                                @endif
                            @else
                                <div class="small text-muted">
                                    <i class="bi bi-cloud-slash me-1"></i>Не создана
                                </div>
                            @endif
                        </div>

                        {{-- Ошибка синхронизации --}}
                        @if($supplierOrder->sync_error)
                            <div class="small text-warning-emphasis mt-2">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                <strong>Ошибка:</strong> {{ $supplierOrder->sync_error }}
                            </div>
                        @endif

                        {{-- Кнопка синхронизации --}}
                        <div class="mt-3">
                            <form method="POST" action="{{ route('supplier-orders.sync', $supplierOrder) }}">
                                @csrf
                                <button type="submit"
                                        class="btn btn-sm w-100 {{ $supplierOrder->sync_error ? 'btn-warning' : ($supplierOrder->supply_moysklad_id ? 'btn-outline-secondary' : 'btn-outline-primary') }}">
                                    <i class="bi bi-arrow-repeat me-1"></i>
                                    @if($supplierOrder->sync_error)
                                        Повторить синхронизацию
                                    @elseif($supplierOrder->supply_moysklad_id)
                                        Синхронизировать
                                    @else
                                        Создать приёмку
                                    @endif
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                {{-- Действия --}}
                @if($supplierOrder->isNew())
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-white py-2">
                            <span class="fw-semibold small text-muted">Действия</span>
                        </div>
                        <div class="card-body py-2">
                            <div class="d-grid gap-2">
                                <a href="{{ route('supplier-orders.edit', $supplierOrder) }}" class="btn btn-secondary">
                                    <i class="bi bi-pencil me-1"></i>Редактировать
                                </a>
                                <form method="POST" action="{{ route('supplier-orders.destroy', $supplierOrder) }}"
                                      onsubmit="return confirm('Удалить поступление №{{ $supplierOrder->number }}? Это действие необратимо.')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-danger w-100">
                                        <i class="bi bi-trash me-1"></i>Удалить
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @endif

            </div>

            <!-- Правая колонка: позиции -->
            <div class="col-md-6">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
                        <span class="fw-semibold small">📦 Позиции поступления</span>
                        @if($supplierOrder->items->count() > 0)
                            <span class="badge bg-secondary">{{ $supplierOrder->items->count() }}</span>
                        @endif
                    </div>
                    <div class="card-body p-0">
                        @if($supplierOrder->items->count() > 0)

                            {{-- Десктоп: таблица --}}
                            <div class="d-none d-md-block">
                                <div class="table-responsive">
                                    <table class="table table-hover table-sm mb-0">
                                        <thead class="table-light">
                                        <tr>
                                            <th>Продукт</th>
                                            <th>Артикул</th>
                                            <th class="text-end">Количество</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach($supplierOrder->items as $item)
                                            <tr>
                                                <td>
                                                    @if($item->product)
                                                        <a href="{{ route('products.show', $item->product->moysklad_id) }}" class="small">
                                                            {{ $item->product->name }}
                                                        </a>
                                                    @else
                                                        <span class="text-muted small">—</span>
                                                    @endif
                                                </td>
                                                <td class="small text-muted">{{ $item->product->sku ?? '—' }}</td>
                                                <td class="text-end">
                                                    <span class="badge bg-primary">{{ number_format($item->quantity, 3) }} м²</span>
                                                </td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                        <tfoot class="table-light">
                                        <tr>
                                            <th colspan="2" class="small">Итого:</th>
                                            <th class="text-end">
                                                <span class="badge bg-primary">{{ number_format($supplierOrder->items->sum('quantity'), 3) }} м²</span>
                                            </th>
                                        </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>

                            {{-- Мобильный: карточки --}}
                            <div class="d-md-none" style="padding:.35rem .4rem">
                                @foreach($supplierOrder->items as $item)
                                    @php
                                        $skuColor = \App\Models\Product::getColorBySku($item->product->sku ?? null);
                                        $skuBg    = $skuColor === '#FFFFFF' ? '' : 'background:' . $skuColor . '18;';
                                    @endphp
                                    <div style="border-left:3px solid {{ $skuColor }};{{ $skuBg }}padding:.3rem .4rem;border-bottom:1px solid #f0f0f0;margin-bottom:.2rem;border-radius:.25rem">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div class="small fw-semibold text-truncate me-2" style="font-size:.8rem;max-width:70%">
                                                {{ $item->product->name ?? '—' }}
                                            </div>
                                            <span class="badge bg-primary" style="font-size:.65rem">{{ number_format($item->quantity, 3) }} м²</span>
                                        </div>
                                        @if($item->product?->sku)
                                            <div class="text-muted mt-1" style="font-size:.7rem">
                                                <i class="bi bi-upc me-1"></i>{{ $item->product->sku }}
                                            </div>
                                        @endif
                                    </div>
                                @endforeach

                                {{-- Итого мобильный --}}
                                <div class="d-flex justify-content-between align-items-center mt-2 pt-1" style="border-top:1px solid #dee2e6">
                                    <span class="small fw-semibold">Итого:</span>
                                    <span class="badge bg-primary" style="font-size:.68rem">{{ number_format($supplierOrder->items->sum('quantity'), 3) }} м²</span>
                                </div>
                            </div>

                        @else
                            <div class="text-center py-4">
                                <i class="bi bi-box-seam display-4 text-muted"></i>
                                <p class="text-muted mt-3 small">Нет позиций</p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

        </div>
    </div>
@endsection
