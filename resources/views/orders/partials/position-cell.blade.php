{{--
    Число позиции заявки, кликабельное целиком — открывает модалку настройки.
    Один партиал на обе колонки: модалке всё равно нужен полный набор данных строки.

    Параметры: $row, $fmt1, $field ('warehouse' | 'produced'), $storeNames, $editable (опц.).
--}}
@php
    $setting = $row['setting'] ?? null;
    $canEdit = ($editable ?? true) && $row['product'] && ! empty($row['stores']);

    $value = $field === 'warehouse' ? $row['warehouseQty'] : $row['producedQty'];
    $delta = $field === 'warehouse'
        ? (float) ($setting->stock_delta ?? 0)
        : (float) ($setting->produced_delta ?? 0);

    $author = $setting?->user?->worker?->name ?? $setting?->user?->name;
    $names  = collect($row['stores'])->map(fn ($id) => $storeNames[$id] ?? $id)->implode(', ');

    if ($field === 'warehouse') {
        $hint = $names ? 'Склады: ' . $names : 'Склад не выбран';
        if ($row['frozen']) {
            $hint .= ' · остаток зафиксирован на входе в производство';
        }
    } else {
        $hint = 'По документам производства: ' . $fmt1($row['producedAuto'] ?? 0);
    }

    if ($delta != 0) {
        $hint .= ' · поправка ' . ($delta > 0 ? '+' : '−') . $fmt1(abs($delta))
            . ($author ? ' · ' . $author : '')
            . ($setting->note ? ' · ' . $setting->note : '');
    }

    if ($canEdit) {
        $hint .= ' · нажмите, чтобы изменить';
    }
@endphp

<span class="d-inline-flex align-items-center gap-1 justify-content-end"
      @if($canEdit)
          role="button"
          style="cursor:pointer"
          data-bs-toggle="modal"
          data-bs-target="#positionModal"
          data-focus="{{ $field }}"
          data-product-id="{{ $row['product']->id }}"
          data-name="{{ $row['name'] }}"
          data-selected="{{ json_encode($row['stores']) }}"
          data-visible-stores="{{ json_encode($row['visibleStores']) }}"
          data-store-qty="{{ json_encode($row['storeQty']) }}"
          data-produced-store="{{ json_encode($row['producedByStore']) }}"
          data-warehouse="{{ $fmt1($row['warehouseQty']) }}"
          data-produced="{{ $fmt1($row['producedQty']) }}"
          data-produced-auto="{{ $fmt1($row['producedAuto'] ?? 0) }}"
          data-note="{{ $setting?->note }}"
          data-has-setting="{{ $setting && $setting->hasCorrections() ? '1' : '0' }}"
      @endif
      title="{{ $hint }}">

    @if($field === 'warehouse' && $row['frozen'])
        <i class="bi bi-snow text-primary" style="font-size:.62rem"
           title="Остаток зафиксирован на входе в производство"></i>
    @endif

    @if($delta != 0)
        <span class="badge bg-warning-subtle text-warning-emphasis" style="font-size:.62rem">факт</span>
    @endif

    <span class="fw-semibold" style="font-variant-numeric: tabular-nums">
        {{ $value !== null ? $fmt1($value) : '—' }}
    </span>

    @if($canEdit)
        <i class="bi bi-pencil-square text-muted"></i>
    @endif
</span>
