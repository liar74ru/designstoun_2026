{{--
    Остаток позиции на складе заявки с уточнением мастера.
    Кликабельна вся ячейка, а не только карандаш.
    Параметры: $row (строка из orders/show), $fmt1, $productionStoreId.
--}}
@php
    $correction = $row['correction'] ?? null;
    $canEdit    = $productionStoreId && $row['product'];

    $author = $correction
        ? ($correction->user?->worker?->name ?? $correction->user?->name)
        : null;

    $hint = $correction
        ? 'МойСклад ' . $fmt1($correction->moysklad_quantity)
            . ' · поправка ' . ($correction->delta > 0 ? '+' : '−') . $fmt1(abs($correction->delta))
            . ($author ? ' · ' . $author : '')
            . ' · ' . $correction->updated_at?->format('d.m.Y')
            . ($correction->note ? ' · ' . $correction->note : '')
        : 'Нажмите, чтобы уточнить фактический остаток';

    $numberClass = $row['done'] || $row['prodQty'] === null
        ? ''
        : ($row['short'] > 0 ? 'text-danger' : 'text-success');
@endphp

<span class="d-inline-flex align-items-center gap-1 justify-content-end {{ $canEdit ? 'correction-cell' : '' }}"
      @if($canEdit)
          role="button"
          style="cursor:pointer"
          data-bs-toggle="modal"
          data-bs-target="#stockCorrectionModal"
          data-product-id="{{ $row['product']->id }}"
          data-name="{{ $row['name'] }}"
          data-base="{{ $fmt1($row['baseQty']) }}"
          data-fact="{{ $fmt1($row['prodQty']) }}"
          data-note="{{ $correction?->note }}"
          data-has-correction="{{ $correction ? '1' : '0' }}"
      @endif
      title="{{ $hint }}">

    @if($correction)
        <span class="badge bg-warning-subtle text-warning-emphasis" style="font-size:.62rem">факт</span>
    @endif

    <span class="fw-semibold {{ $numberClass }}" style="font-variant-numeric: tabular-nums">
        {{ $row['prodQty'] !== null ? $fmt1($row['prodQty']) : '—' }}
    </span>

    @if($canEdit)
        <i class="bi bi-pencil-square text-muted"></i>
    @endif
</span>
