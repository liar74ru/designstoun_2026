@php
    $rawItems     = $workshop->items->where('role', \App\Models\WorkshopItem::ROLE_RAW);
    $packageItems = $workshop->items->where('role', \App\Models\WorkshopItem::ROLE_PACKAGE);
    $skuColor     = \App\Models\Product::getColorBySku($rawItems->first()?->product?->sku);
    $skuBg        = $skuColor === '#FFFFFF' ? '#fff' : $skuColor . '18';
    $canModify    = auth()->user()?->can('modify', $workshop);
    $isActive     = $workshop->status === 'active';
    $btnStyle     = 'width:22px;height:22px;padding:0;font-size:.65rem';
    $uomOf        = fn ($product) => strtr((string) $product?->uom, ['м2' => 'м²', 'м3' => 'м³']);
@endphp
<div style="border-radius:.35rem;border:1px solid #dee2e6;border-left:4px solid {{ $skuColor }};border-right:4px solid {{ $skuColor }};background:{{ $skuBg }};box-shadow:0 1px 2px rgba(0,0,0,.07)">
    <div style="padding:.1rem .35rem">

        {{-- Строка 1: дата + ID слева, кнопки справа --}}
        <div class="d-flex justify-content-between align-items-center" style="margin-bottom:.2rem">
            <a href="{{ route('workshops.show', $workshop) }}" class="text-muted text-decoration-none" style="font-size:.72rem">
                {{ $workshop->created_at->format('d.m.Y H:i') }}
                <span class="text-secondary ms-1">#{{ $workshop->id }}</span>
            </a>
            <div class="d-flex gap-1 align-items-center">
                @if($canModify && $isActive)
                    <a href="{{ route('workshops.edit', $workshop) }}"
                       class="btn btn-success d-inline-flex align-items-center justify-content-center"
                       style="{{ $btnStyle }}" title="Изменить">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <form action="{{ route('workshops.mark-completed', $workshop) }}" method="POST" class="d-inline-flex"
                          onsubmit="return confirm('Закрыть операцию?')" data-submit-guard>
                        @csrf @method('PATCH')
                        <button type="submit" class="btn btn-warning d-inline-flex align-items-center justify-content-center"
                                style="{{ $btnStyle }}" title="Закрыть">
                            <i class="bi bi-check2-circle"></i>
                        </button>
                    </form>
                @endif
                <form action="{{ route('workshops.copy', $workshop) }}" method="POST" class="d-inline-flex" data-submit-guard>
                    @csrf
                    <button type="submit" class="btn btn-outline-info d-inline-flex align-items-center justify-content-center"
                            style="{{ $btnStyle }}" title="Копия">
                        <i class="bi bi-copy"></i>
                    </button>
                </form>
                <a href="{{ route('workshops.show', $workshop) }}"
                   class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center"
                   style="{{ $btnStyle }}" title="Просмотр">
                    <i class="bi bi-eye"></i>
                </a>
                @if($canModify && !$isActive)
                    <form action="{{ route('workshops.reset-status', $workshop) }}" method="POST" class="d-inline-flex"
                          onsubmit="return confirm('Сбросить статус?')" data-submit-guard>
                        @csrf @method('PATCH')
                        <button type="submit" class="btn btn-outline-warning d-inline-flex align-items-center justify-content-center"
                                style="{{ $btnStyle }}" title="Сбросить статус">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </button>
                    </form>
                @endif
                @if($canModify && $isActive)
                    <form action="{{ route('workshops.destroy', $workshop) }}" method="POST" class="d-inline-flex"
                          onsubmit="return confirm('Удалить операцию?')" data-submit-guard>
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger d-inline-flex align-items-center justify-content-center"
                                style="{{ $btnStyle }}" title="Удалить">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                @endif
            </div>
        </div>

        {{-- Строка 2: работник слева, статус справа --}}
        <div class="d-flex justify-content-between align-items-center" style="margin-bottom:.2rem">
            <span class="text-muted text-truncate me-2" style="font-size:.72rem">
                <i class="bi bi-person me-1"></i>{{ $workshop->packer->name ?? '—' }}
            </span>
            <div class="d-flex gap-1 align-items-center flex-shrink-0">
                @if($workshop->moysklad_sync_status && !$workshop->isSynced())
                    <span class="badge {{ $workshop->syncStatusBadgeClass() }}" style="font-size:.65rem">{{ $workshop->syncStatusLabel() }}</span>
                @endif
                @if($isActive)
                    <span class="badge bg-success" style="font-size:.65rem">Активна</span>
                @elseif($workshop->status === 'completed')
                    <span class="badge bg-warning text-dark" style="font-size:.65rem">Закрыта</span>
                @else
                    <span class="badge bg-danger" style="font-size:.65rem">Ошибка</span>
                @endif
            </div>
        </div>

        {{-- Блок: сырьё --}}
        @if($rawItems->isNotEmpty())
            <div style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem;margin-bottom:.2rem">
                @foreach($rawItems as $item)
                    <div class="d-flex justify-content-between align-items-start" style="{{ !$loop->last ? 'margin-bottom:.1rem' : '' }}">
                        <div class="d-flex align-items-start me-2" style="max-width:75%;min-width:0">
                            <ion-icon name="{{ \App\Models\Product::getIconBySku($item->product?->sku) }}" class="text-secondary me-1 flex-shrink-0" style="font-size:.82rem;margin-top:.1rem"></ion-icon>
                            <span class="fw-semibold text-dark" style="font-size:.82rem;line-height:1.2;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word">{{ $item->product->name }}</span>
                        </div>
                        <span class="fw-bold text-primary text-nowrap" style="font-size:.82rem">
                            {{ number_format($item->quantity, 3, ',', '.') }} <span class="fw-normal" style="font-size:.7rem">{{ $uomOf($item->product) }}</span>
                        </span>
                    </div>
                @endforeach
                @if($rawItems->count() > 1)
                    <div class="d-flex justify-content-end" style="margin-top:.15rem">
                        <span class="fw-semibold text-nowrap" style="font-size:.72rem">
                            Итого: {{ number_format($workshop->total_quantity, 3, ',', '.') }} {{ $uomOf($rawItems->first()?->product) }}
                        </span>
                    </div>
                @endif
            </div>
        @endif

        {{-- Блок: упаковка --}}
        @if($packageItems->isNotEmpty())
            <div style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem;margin-bottom:.2rem">
                @foreach($packageItems as $item)
                    <div class="d-flex justify-content-between align-items-baseline">
                        <span class="text-muted text-truncate me-2" style="font-size:.68rem">
                            <i class="bi bi-box-seam me-1"></i>{{ $item->product->name }}
                        </span>
                        <span class="text-muted text-nowrap" style="font-size:.68rem">× {{ number_format($item->quantity, 0) }} {{ $uomOf($item->product) }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Последняя строка: отдел слева, склад справа --}}
        <div class="d-flex justify-content-between" style="border-top:1px solid rgba(108,117,125,.2);padding-top:.2rem">
            <span class="text-muted text-truncate me-2" style="font-size:.65rem">
                <i class="bi bi-diagram-3 me-1"></i>{{ $workshop->department?->name ?? '—' }}
            </span>
            <span class="text-muted text-truncate" style="font-size:.65rem">
                <i class="bi bi-building me-1"></i>{{ $workshop->store->name ?? '—' }}
            </span>
        </div>

    </div>
</div>
