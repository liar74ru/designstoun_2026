{{--
    Цифры остатка сеткой 2×2 — мобильная карточка склада и строка «Итого».
    Параметры: $quantity, $reserved, $inTransit, $available.
--}}
@php
    $figures = [
        ['Кол-во',   $quantity,  ''],
        ['Резерв',   $reserved,  'text-warning-emphasis'],
        ['В пути',   $inTransit, 'text-info-emphasis'],
        ['Доступно', $available, 'text-success'],
    ];
@endphp
<div class="row row-cols-2 gx-3 gy-1 small mt-1">
    @foreach($figures as [$label, $value, $class])
        <div class="col">
            <div class="d-flex justify-content-between gap-2 border-bottom">
                <span class="text-muted">{{ $label }}</span>
                <span class="fw-semibold text-nowrap {{ $value > 0 ? $class : ($value < 0 ? 'text-danger' : 'text-muted') }}">
                    {{ $value != 0 ? number_format($value, 3, ',', ' ') : '0' }}
                </span>
            </div>
        </div>
    @endforeach
</div>
