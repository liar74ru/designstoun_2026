@extends('layouts.app')
@section('title', 'Приёмки камня — по партиям')

@section('content')
    <div class="container py-3 py-md-4">

        <x-page-header title="📦 Приёмки камня" :hide-mobile="true">
            <x-slot:actions>
                <a href="{{ route('stone-receptions.create') }}" class="btn btn-success btn-lg px-4">
                    <i class="bi bi-plus-circle"></i> Новая приёмка
                </a>
            </x-slot:actions>
        </x-page-header>

        @include('partials.alerts')

        @include('stone-receptions.partials.mobile-tabs', ['activeTab' => 'index'])

        {{-- Десктоп: переключатель вида --}}
        <ul class="nav nav-pills mb-3 mb-md-4 d-none d-md-flex">
            <li class="nav-item">
                <a class="nav-link active py-1 px-3" href="{{ route('stone-receptions.index') }}">
                    <i class="bi bi-table"></i> По партиям
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link py-1 px-3" href="{{ route('stone-receptions.logs') }}">
                    <i class="bi bi-journal-text"></i> По приёмкам
                </a>
            </li>
        </ul>

        {{-- ═══════════════════════ ФИЛЬТРЫ ═══════════════════════ --}}
        @include('partials.filters', [
            'filterCutters'     => $filterCutters,
            'cutterParam'       => 'cutter_id',
            'filterRawProducts' => $filterRawProducts,
            'rawProductParam'   => 'raw_product_id',
            'filterProducts'    => $filterProducts,
            'showStatus'        => 'multi',
            'statusOptions'     => ['active' => 'Активна', 'completed' => 'Завершена', 'error' => 'Ошибка'],
            'statusDefaults'    => ['active', 'error'],
            'showSyncStatus'    => true,
            'syncStatusOptions' => ['synced' => 'Синхронизирована', 'not_synced' => 'Не синхр.'],
        ])

        {{-- ═══════════════════════ ДАННЫЕ ═══════════════════════ --}}
        @if($receptions->count() > 0)

            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-end align-items-center py-2">
                    <span class="text-muted small">Найдено: {{ $receptions->total() }}</span>
                </div>

                {{-- ─── ДЕСКТОП: таблица ─── --}}
                <div class="d-none d-md-block">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Дата</th>
                                <th>Продукция</th>
                                <th>Итого</th>
                                <th>Сырьё</th>
                                <th>Расход</th>
                                <th>Приёмщик</th>
                                <th>Пильщик</th>
                                <th>Склад</th>
                                <th>Статус</th>
                                <th class="text-end">Действия</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($receptions as $reception)
                                <tr class="{{ $reception->status == 'processed' ? 'table-success' : ($reception->status == 'completed' ? 'table-warning' : ($reception->status == 'error' ? 'table-danger' : '')) }}">
                                    <td>{{ $reception->id }}</td>
                                    <td class="text-nowrap">{{ $reception->created_at->format('d.m.Y H:i') }}</td>
                                    <td>
                                        @foreach($reception->items as $item)
                                            <div class="{{ !$loop->last ? 'mb-1 pb-1 border-bottom' : '' }}">
                                                <strong>{{ $item->product->name }}</strong><br>
                                                <small class="text-muted">{{ $item->product->sku }}</small>
                                                <span class="badge bg-info ms-1">{{ number_format($item->quantity, 3) }} м²</span>
                                            </div>
                                        @endforeach
                                    </td>
                                    <td><span class="badge bg-primary">{{ number_format($reception->total_quantity, 3) }} м²</span></td>
                                    <td>
                                        @if($reception->rawMaterialBatch)
                                            @php
                                                $bInit = (float) ($reception->rawMaterialBatch->initial_quantity ?? 0);
                                                $bRem  = (float) ($reception->rawMaterialBatch->remaining_quantity ?? 0);
                                            @endphp
                                            <a href="{{ route('raw-batches.show', $reception->rawMaterialBatch) }}">
                                                {{ $reception->rawMaterialBatch->product->name }}
                                            </a>
                                            <br>
                                            <div class="d-flex gap-1 mt-1">
                                                <span title="Всего в партии" style="font-size:.68rem;padding:1px 5px;border-radius:3px;background:#e9ecef;color:#495057;white-space:nowrap">{{ number_format($bInit, 3, '.', '') }}</span>
                                                <span title="Доступно в партии" style="font-size:.68rem;padding:1px 5px;border-radius:3px;background:{{ $bRem > 0 ? '#cff4fc' : '#fff3cd' }};color:{{ $bRem > 0 ? '#055160' : '#664d03' }};white-space:nowrap">{{ number_format($bRem, 3, '.', '') }}</span>
                                            </div>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td><span class="badge bg-warning text-dark">{{ number_format($reception->raw_quantity_used, 3) }} м³</span></td>
                                    <td>{{ $reception->receiver->name ?? '—' }}</td>
                                    <td>{{ $reception->cutter->name ?? '—' }}</td>
                                    <td>{{ $reception->store->name ?? '—' }}</td>
                                    <td>
                                        @if($reception->status == 'active')
                                            <span class="badge bg-success">Активна</span>
                                        @elseif($reception->status == 'completed')
                                            <span class="badge bg-warning text-dark">Завершена</span>
                                        @elseif($reception->status == 'processed')
                                            <span class="badge bg-secondary">Обработана</span>
                                            @if($reception->moysklad_processing_id)
                                                <br><small class="text-muted">{{ substr($reception->moysklad_processing_id, 0, 8) }}…</small>
                                            @endif
                                        @elseif($reception->status == 'error')
                                            <span class="badge bg-danger">Ошибка</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1 justify-content-end">
                                            @if($reception->status == 'active')
                                                <a href="{{ route('stone-receptions.edit', $reception) }}"
                                                   class="btn btn-sm btn-success" title="Редактировать">
                                                    <i class="bi bi-plus-lg"></i>
                                                </a>
                                            @endif
                                            @if($reception->status == 'active')
                                                <form method="POST" action="{{ route('stone-receptions.mark-completed', $reception) }}" class="d-inline"
                                                      onsubmit="return confirm('Завершить приёмку?')">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="btn btn-sm btn-warning" title="Завершить приёмку">
                                                        <i class="bi bi-check2-circle"></i>
                                                    </button>
                                                </form>
                                            @endif
                                            <form action="{{ route('stone-receptions.copy', $reception) }}"
                                                  method="POST" class="d-inline">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-info" title="Копировать">
                                                    <i class="bi bi-copy"></i>
                                                </button>
                                            </form>
                                            <a href="{{ route('stone-receptions.show', $reception) }}"
                                               class="btn btn-sm btn-outline-secondary" title="Просмотр">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            @if($reception->status != 'active')
                                                <form action="{{ route('stone-receptions.reset-status', $reception) }}"
                                                      method="POST" class="d-inline"
                                                      onsubmit="return confirm('Сбросить статус на Активна?')">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="btn btn-sm btn-outline-warning" title="Сбросить статус">
                                                        <i class="bi bi-arrow-counterclockwise"></i>
                                                    </button>
                                                </form>
                                            @endif
                                            @if($reception->status == 'active')
                                                <form action="{{ route('stone-receptions.destroy', $reception) }}"
                                                      method="POST" class="d-inline"
                                                      onsubmit="return confirm('Удалить приёмку?')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Удалить">
                                                        <i class="bi bi-trash"></i>
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

                {{-- ─── МОБИЛЬНЫЙ: карточки ─── --}}
                <div class="d-md-none" style="padding:.25rem">
                    @foreach($receptions as $reception)
                        @php
                            $skuColor = \App\Models\Product::getColorBySku($reception->rawMaterialBatch?->product?->sku);
                            $skuBg    = $skuColor === '#FFFFFF' ? '#fff' : $skuColor . '18';
                        @endphp
                        <div style="margin-bottom:.35rem;border-radius:.35rem;border:1px solid #dee2e6;border-left:4px solid {{ $skuColor }};border-right:4px solid {{ $skuColor }};background:{{ $skuBg }};box-shadow:0 1px 2px rgba(0,0,0,.07)">
                            <div style="padding:.1rem .35rem">

                                {{-- Строка 1: дата + ID слева, чекбокс + кнопки справа --}}
                                <div class="d-flex justify-content-between align-items-center" style="margin-bottom:.2rem">
                                    <span class="text-muted" style="font-size:.72rem">
                                        {{ $reception->created_at->format('d.m.Y H:i') }}
                                        <span class="text-secondary ms-1">#{{ $reception->id }}</span>
                                    </span>
                                    <div class="d-flex gap-1 align-items-center">
                                        @if($reception->status == 'active')
                                            <a href="{{ route('stone-receptions.edit', $reception) }}"
                                               class="btn btn-success d-inline-flex align-items-center justify-content-center"
                                               style="width:22px;height:22px;padding:0;font-size:.65rem" title="Редактировать">
                                                <i class="bi bi-plus-lg"></i>
                                            </a>
                                        @endif
                                        @if($reception->status == 'active')
                                            <form action="{{ route('stone-receptions.mark-completed', $reception) }}"
                                                  method="POST" class="d-inline-flex"
                                                  onsubmit="return confirm('Завершить приёмку?')">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit"
                                                        class="btn btn-warning d-inline-flex align-items-center justify-content-center"
                                                        style="width:22px;height:22px;padding:0;font-size:.65rem" title="Завершить приёмку">
                                                    <i class="bi bi-check2-circle"></i>
                                                </button>
                                            </form>
                                        @endif
                                        <form action="{{ route('stone-receptions.copy', $reception) }}"
                                              method="POST" class="d-inline-flex">
                                            @csrf
                                            <button type="submit"
                                                    class="btn btn-outline-info d-inline-flex align-items-center justify-content-center"
                                                    style="width:22px;height:22px;padding:0;font-size:.65rem" title="Копировать">
                                                <i class="bi bi-copy"></i>
                                            </button>
                                        </form>
                                        <a href="{{ route('stone-receptions.show', $reception) }}"
                                           class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center"
                                           style="width:22px;height:22px;padding:0;font-size:.65rem" title="Просмотр">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        @if($reception->status != 'active')
                                            <form action="{{ route('stone-receptions.reset-status', $reception) }}"
                                                  method="POST" class="d-inline-flex"
                                                  onsubmit="return confirm('Сбросить статус на Активна?')">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit"
                                                        class="btn btn-outline-warning d-inline-flex align-items-center justify-content-center"
                                                        style="width:22px;height:22px;padding:0;font-size:.65rem" title="Сбросить статус">
                                                    <i class="bi bi-arrow-counterclockwise"></i>
                                                </button>
                                            </form>
                                        @endif
                                        @if($reception->status == 'active')
                                            <form action="{{ route('stone-receptions.destroy', $reception) }}"
                                                  method="POST" class="d-inline-flex"
                                                  onsubmit="return confirm('Удалить приёмку?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="btn btn-outline-danger d-inline-flex align-items-center justify-content-center"
                                                        style="width:22px;height:22px;padding:0;font-size:.65rem" title="Удалить">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </div>

                                {{-- Строка 2: пильщик слева, статус справа --}}
                                <div class="d-flex justify-content-between align-items-center" style="margin-bottom:.2rem">
                                    <span class="fw-semibold small">
                                        <i class="bi bi-hammer text-secondary me-1"></i>{{ $reception->cutter->name ?? '—' }}
                                    </span>
                                    <div class="d-flex gap-1 align-items-center">
                                        @if($reception->moysklad_sync_status && !$reception->isSynced())
                                            <span class="badge {{ $reception->syncStatusBadgeClass() }}" style="font-size:.65rem">{{ $reception->syncStatusLabel() }}</span>
                                        @endif
                                        @if($reception->status == 'active')
                                            <span class="badge bg-success" style="font-size:.65rem">Активна</span>
                                        @elseif($reception->status == 'completed')
                                            <span class="badge bg-warning text-dark" style="font-size:.65rem">Завершена</span>
                                        @elseif($reception->status == 'processed')
                                            <span class="badge bg-secondary" style="font-size:.65rem">Обработана</span>
                                        @elseif($reception->status == 'error')
                                            <span class="badge bg-danger" style="font-size:.65rem">Ошибка</span>
                                        @endif
                                    </div>
                                </div>

                                {{-- Блок: продукция --}}
                                @if($reception->items->count() > 0)
                                    <div style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem;margin-bottom:.2rem">
                                        @foreach($reception->items as $item)
                                            <div class="d-flex justify-content-between align-items-baseline" style="{{ !$loop->last ? 'margin-bottom:.1rem' : '' }}">
                                                <span class="text-truncate me-2" style="font-size:.72rem;max-width:80%">
                                                    <i class="bi bi-grid-3x3 text-secondary me-1"></i>{{ $item->product->name }}
                                                </span>
                                                <span class="fw-semibold text-primary text-nowrap" style="font-size:.72rem">
                                                    {{ number_format($item->quantity, 3, ',', '.') }} м²
                                                </span>
                                            </div>
                                        @endforeach
                                        <div class="d-flex justify-content-end" style="margin-top:.15rem">
                                            <span class="fw-semibold text-nowrap" style="font-size:.72rem">
                                                Итого: {{ number_format($reception->total_quantity, 3, ',', '.') }} м²
                                            </span>
                                        </div>
                                    </div>
                                @endif

                                {{-- Блок: сырьё --}}
                                @if($reception->rawMaterialBatch)
                                    @php
                                        $bInit = (float) ($reception->rawMaterialBatch->initial_quantity ?? 0);
                                        $bRem  = (float) ($reception->rawMaterialBatch->remaining_quantity ?? 0);
                                    @endphp
                                    <div class="d-flex justify-content-between align-items-center" style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem;margin-bottom:.2rem">
                                        <span class="text-muted text-truncate me-2" style="font-size:.72rem">
                                            <i class="bi bi-box me-1"></i>
                                            <a href="{{ route('raw-batches.show', $reception->rawMaterialBatch) }}" class="text-muted">
                                                {{ $reception->rawMaterialBatch->product->name }}
                                            </a>
                                        </span>
                                        <div class="d-flex gap-1 flex-shrink-0">
                                            <span title="Всего в партии" style="font-size:.65rem;padding:1px 4px;border-radius:3px;background:#e9ecef;color:#495057;white-space:nowrap">{{ number_format($bInit, 3, '.', '') }}</span>
                                            <span title="Доступно в партии" style="font-size:.65rem;padding:1px 4px;border-radius:3px;background:{{ $bRem > 0 ? '#cff4fc' : '#fff3cd' }};color:{{ $bRem > 0 ? '#055160' : '#664d03' }};white-space:nowrap">{{ number_format($bRem, 3, '.', '') }}</span>
                                        </div>
                                    </div>
                                @endif

                                {{-- Последняя строка: приёмщик справа --}}
                                @if($reception->receiver)
                                    <div class="d-flex justify-content-end" style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem">
                                        <span class="text-muted" style="font-size:.65rem">
                                            <i class="bi bi-person-gear me-1"></i>{{ $reception->receiver->name }}
                                        </span>
                                    </div>
                                @endif

                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Пагинация --}}
                <div class="d-flex justify-content-between align-items-center p-2 p-md-3 border-top">
                    <span class="text-muted small">
                        Показано {{ $receptions->firstItem() }}–{{ $receptions->lastItem() }} из {{ $receptions->total() }}
                    </span>
                    {{ $receptions->links() }}
                </div>

            </div>

        @else
            <div class="text-center py-5">
                <i class="bi bi-inbox display-1 text-muted"></i>
                <h4 class="text-muted mt-3">Приёмок не найдено</h4>
            </div>
        @endif

    </div>
@endsection

