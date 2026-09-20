{{-- Строка сводки в правой панели карточки заявки: подпись слева, число справа. --}}
<div class="d-flex justify-content-between align-items-baseline py-1 {{ ($last ?? false) ? '' : 'border-bottom' }}">
    <span class="text-muted" style="font-size:.78rem">{{ $label }}</span>
    <span class="{{ $class ?? '' }}" style="font-variant-numeric: tabular-nums">{{ $value }}</span>
</div>
