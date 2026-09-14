@extends('layouts.app')

@section('title', $product->name)

@push('styles')
    <style>
        /* Tailwind (app.css) задаёт .collapse { visibility: collapse } — раскрытый блок Bootstrap
           получал высоту, но оставался невидимым. */
        .collapse.show, .collapsing { visibility: visible; }

        .info-block-toggle {
            width: 100%;
            display: flex;
            align-items: center;
            gap: .5rem;
            min-width: 0;
            border: 0;
            border-bottom: 1px solid #dee2e6;
            color: inherit;
            font-weight: 600;
            text-align: left;
        }
        .info-block-toggle.collapsed { border-bottom: 0; border-radius: .35rem; }
        .info-block-toggle .bi-chevron-right { transition: transform .15s ease; }
        .info-block-toggle:not(.collapsed) .bi-chevron-right { transform: rotate(90deg); }
        @media (prefers-reduced-motion: reduce) {
            .info-block-toggle .bi-chevron-right { transition: none; }
        }
    </style>
@endpush

@section('content')
    @php
        $uom      = $product->stocks->first()?->store?->uom ?? 'шт';
        $skuColor = \App\Models\Product::getColorBySku($product->sku);
        $skuBg    = $skuColor === '#FFFFFF' ? '' : 'background:' . $skuColor . '18;';

        $extraAttributes = is_string($product->attributes) ? json_decode($product->attributes, true) : ($product->attributes ?? []);
        $extraAttributes = collect($extraAttributes)->filter(fn ($value, $key) => $value && !in_array($key, ['meta', 'zones', 'slots']));

        // Склады, где всё по нулям (остаток, резерв, в пути), не показываем — только считаем.
        $visibleStocks     = $product->stocks->reject(fn ($s) => $s->quantity == 0 && $s->reserved == 0 && $s->in_transit == 0);
        $hiddenStocksCount = $product->stocks->count() - $visibleStocks->count();
        $refreshConfirm  = "return confirm('Обновить данные товара из МойСклад?')";
    @endphp

    <div class="container py-3 py-md-4">
        <x-page-header :title="$product->name" :backUrl="$backUrl">
            <x-slot:actions>
                <a href="{{ route('products.refresh', $product->moysklad_id) }}"
                   class="btn btn-warning"
                   onclick="{{ $refreshConfirm }}">
                    <i class="bi bi-arrow-repeat"></i> Обновить
                </a>
            </x-slot:actions>
            <x-slot:mobileActions>
                <a href="{{ route('products.refresh', $product->moysklad_id) }}"
                   class="btn btn-warning btn-sm"
                   title="Обновить"
                   onclick="{{ $refreshConfirm }}">
                    <i class="bi bi-arrow-repeat"></i>
                </a>
            </x-slot:mobileActions>
        </x-page-header>

        @include('partials.alerts')

        <div class="d-flex flex-column gap-2">
            {{-- Общий остаток --}}
            <div class="info-block mb-0" style="border-left:4px solid {{ $skuColor }}">
                <div class="info-block-header d-flex justify-content-between align-items-center small fw-semibold">
                    <span>Общий остаток</span>
                    @if($product->total_quantity > 0)
                        <span class="badge bg-success">В наличии</span>
                    @else
                        <span class="badge bg-danger">Нет в наличии</span>
                    @endif
                </div>
                <div class="info-block-body" style="{{ $skuBg }}">
                    <div class="fs-3 fw-semibold lh-sm">
                        {{ number_format($product->total_quantity, 3, ',', ' ') }}
                        <span class="fs-6 fw-normal text-muted">{{ $uom }}</span>
                    </div>
                    @if($product->stocks->isNotEmpty())
                        <div class="d-flex flex-wrap column-gap-3 small text-muted mt-1">
                            <span>Доступно <b class="text-body">{{ number_format($visibleStocks->sum('available'), 3, ',', ' ') }}</b></span>
                            <span>Резерв <b class="text-body">{{ number_format($visibleStocks->sum('reserved'), 3, ',', ' ') }}</b></span>
                            <span>В пути <b class="text-body">{{ number_format($visibleStocks->sum('in_transit'), 3, ',', ' ') }}</b></span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Остатки по складам --}}
            <div class="info-block mb-0">
                <div class="info-block-header d-flex justify-content-between align-items-center small fw-semibold">
                    <span>Остатки по складам</span>
                    <form action="{{ route('products.stocks.sync', $product->moysklad_id) }}"
                          method="POST"
                          data-submit-guard
                          onsubmit="return confirm('Обновить остатки по складам из МойСклад?')">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-primary py-0" title="Синхронизировать остатки">
                            <i class="bi bi-arrow-repeat"></i>
                            <span class="d-none d-md-inline">Синхронизировать остатки</span>
                        </button>
                    </form>
                </div>
                <div class="info-block-body">
                    @if($visibleStocks->isNotEmpty())
                        {{-- Десктоп --}}
                        <div class="d-none d-md-block table-responsive">
                            <table class="table table-sm table-hover mb-0 small">
                                <thead class="table-light">
                                <tr>
                                    <th>Склад</th>
                                    <th class="text-end">Кол-во</th>
                                    <th class="text-end">Резерв</th>
                                    <th class="text-end">В пути</th>
                                    <th class="text-end">Доступно</th>
                                    <th>Обновлено</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($visibleStocks as $stock)
                                    <tr>
                                        <td>
                                            <strong>{{ $stock->store->name ?? 'Неизвестный склад' }}</strong>
                                            @if($stock->store?->path_name)
                                                <div class="text-muted" style="font-size:.75rem">{{ $stock->store->path_name }}</div>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            <span class="badge bg-primary">{{ number_format($stock->quantity, 3, ',', ' ') }}</span>
                                        </td>
                                        <td class="text-end">
                                            @if($stock->reserved > 0)
                                                <span class="badge bg-warning text-dark">{{ number_format($stock->reserved, 3, ',', ' ') }}</span>
                                            @else
                                                <span class="text-muted">0</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            @if($stock->in_transit > 0)
                                                <span class="badge bg-info">{{ number_format($stock->in_transit, 3, ',', ' ') }}</span>
                                            @else
                                                <span class="text-muted">0</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            @if($stock->available > 0)
                                                <span class="badge bg-success">{{ number_format($stock->available, 3, ',', ' ') }}</span>
                                            @else
                                                <span class="text-muted">0</span>
                                            @endif
                                        </td>
                                        <td class="text-muted text-nowrap">{{ $stock->updated_at->format('d.m.Y H:i') }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                                <tfoot class="table-light">
                                <tr>
                                    <th>Итого:</th>
                                    <th class="text-end">{{ number_format($visibleStocks->sum('quantity'), 3, ',', ' ') }}</th>
                                    <th class="text-end">{{ number_format($visibleStocks->sum('reserved'), 3, ',', ' ') }}</th>
                                    <th class="text-end">{{ number_format($visibleStocks->sum('in_transit'), 3, ',', ' ') }}</th>
                                    <th class="text-end">{{ number_format($visibleStocks->sum('available'), 3, ',', ' ') }}</th>
                                    <th></th>
                                </tr>
                                </tfoot>
                            </table>
                        </div>

                        {{-- Мобильный --}}
                        <div class="d-md-none d-flex flex-column gap-1">
                            @foreach($visibleStocks as $stock)
                                <div class="border rounded p-2">
                                    <div class="fw-semibold lh-sm">{{ $stock->store->name ?? 'Неизвестный склад' }}</div>
                                    @if($stock->store?->path_name)
                                        <div class="text-muted" style="font-size:.75rem">{{ $stock->store->path_name }}</div>
                                    @endif
                                    @include('products.partials.stock-figures', [
                                        'quantity'  => $stock->quantity,
                                        'reserved'  => $stock->reserved,
                                        'inTransit' => $stock->in_transit,
                                        'available' => $stock->available,
                                    ])
                                    <div class="text-end text-muted mt-1" style="font-size:.75rem">
                                        обновлено {{ $stock->updated_at->format('d.m.Y H:i') }}
                                    </div>
                                </div>
                            @endforeach
                            <div class="border rounded p-2 bg-light">
                                <div class="fw-semibold lh-sm">Итого</div>
                                @include('products.partials.stock-figures', [
                                    'quantity'  => $visibleStocks->sum('quantity'),
                                    'reserved'  => $visibleStocks->sum('reserved'),
                                    'inTransit' => $visibleStocks->sum('in_transit'),
                                    'available' => $visibleStocks->sum('available'),
                                ])
                            </div>
                        </div>

                        @if($hiddenStocksCount > 0)
                            <div class="small text-muted mt-2">
                                * Показаны только склады с ненулевым остатком.
                                Складов с нулевым остатком: {{ $hiddenStocksCount }}
                            </div>
                        @endif
                    @elseif($hiddenStocksCount > 0)
                        <div class="text-center text-muted py-3">
                            <i class="bi bi-box-seam d-block mb-2" style="font-size: 2rem;"></i>
                            <p class="mb-0 text-body">На всех складах нулевой остаток</p>
                            <small>Складов: {{ $hiddenStocksCount }}</small>
                        </div>
                    @else
                        <div class="text-center text-muted py-3">
                            <i class="bi bi-box-seam d-block mb-2" style="font-size: 2rem;"></i>
                            <p class="mb-0 text-body">Нет данных об остатках по складам</p>
                            <small>Нажмите <i class="bi bi-arrow-repeat"></i> в заголовке блока, чтобы загрузить данные</small>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Основная информация --}}
            <div class="info-block mb-0">
                @include('products.partials.collapsible-header', [
                    'target' => 'product-main-info',
                    'title'  => 'Основная информация',
                    'hint'   => ($product->sku ?? '—') . ' · коэф. '
                        . \App\Support\RateFormula::formatCoeff($product->prod_cost_coeff) . ' / '
                        . \App\Support\RateFormula::formatCoeff($product->master_cost_coeff),
                ])
                <div class="collapse" id="product-main-info">
                    <div class="info-block-body">
                        <dl class="row mb-0 small">
                            <dt class="col-5 col-md-3 fw-normal text-muted">Артикул</dt>
                            <dd class="col-7 col-md-9"><span class="badge bg-secondary">{{ $product->sku ?? '—' }}</span></dd>

                            <dt class="col-5 col-md-3 fw-normal text-muted">Код</dt>
                            <dd class="col-7 col-md-9"><span class="badge bg-secondary">{{ $product->code ?? '—' }}</span></dd>

                            <dt class="col-5 col-md-3 fw-normal text-muted">Группа</dt>
                            <dd class="col-7 col-md-9">{{ $product->group_name ?? '—' }}</dd>

                            <dt class="col-5 col-md-3 fw-normal text-muted">ID в МойСклад</dt>
                            <dd class="col-7 col-md-9 text-break"><code class="text-muted">{{ $product->moysklad_id }}</code></dd>

                            <dt class="col-5 col-md-3 fw-normal text-muted">prodCostCoeff</dt>
                            <dd class="col-7 col-md-9">{{ \App\Support\RateFormula::formatCoeff($product->prod_cost_coeff) }}</dd>

                            <dt class="col-5 col-md-3 fw-normal text-muted">masterCostCoeff</dt>
                            <dd class="col-7 col-md-9">{{ \App\Support\RateFormula::formatCoeff($product->master_cost_coeff) }}</dd>

                            <dt class="col-5 col-md-3 fw-normal text-muted">Статус</dt>
                            <dd class="col-7 col-md-9">
                                @if($product->is_active)
                                    <span class="badge bg-success">Активен</span>
                                @else
                                    <span class="badge bg-secondary">Неактивен</span>
                                @endif
                            </dd>

                            <dt class="col-5 col-md-3 fw-normal text-muted">Добавлен</dt>
                            <dd class="col-7 col-md-9">{{ $product->created_at->format('d.m.Y H:i') }}</dd>

                            <dt class="col-5 col-md-3 fw-normal text-muted">Обновлён</dt>
                            <dd class="col-7 col-md-9 mb-0">{{ $product->updated_at->format('d.m.Y H:i') }}</dd>
                        </dl>
                    </div>
                </div>
            </div>

            {{-- Описание --}}
            @if($product->description)
                <div class="info-block mb-0">
                    @include('products.partials.collapsible-header', [
                        'target' => 'product-description',
                        'title'  => 'Описание',
                        'hint'   => $product->description,
                    ])
                    <div class="collapse" id="product-description">
                        <div class="info-block-body small">{{ $product->description }}</div>
                    </div>
                </div>
            @endif

            {{-- Дополнительные атрибуты --}}
            <div class="info-block mb-0">
                @include('products.partials.collapsible-header', [
                    'target' => 'product-attributes',
                    'title'  => 'Дополнительные атрибуты',
                    'hint'   => $extraAttributes->isNotEmpty() ? $extraAttributes->count() . ' шт.' : 'нет',
                ])
                <div class="collapse" id="product-attributes">
                    <div class="info-block-body">
                        @if($extraAttributes->isNotEmpty())
                            <dl class="row mb-0 small">
                                @foreach($extraAttributes as $key => $value)
                                    <dt class="col-5 col-md-3 fw-normal text-muted">{{ ucfirst(str_replace('_', ' ', $key)) }}</dt>
                                    <dd class="col-7 col-md-9 text-break {{ $loop->last ? 'mb-0' : '' }}">{{ $value }}</dd>
                                @endforeach
                            </dl>
                        @else
                            <p class="small text-muted mb-0">Нет дополнительных атрибутов</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
