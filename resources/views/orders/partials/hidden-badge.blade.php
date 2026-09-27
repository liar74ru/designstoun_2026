{{--
    Плашка «Скрыто: <отделы>» — для каких отделов позиция скрыта в списке заявок.
    Параметры: $order (его отделы дают названия), $row, $class.
    Плашка рисуется и пустой (d-none): её обновляет скрипт orders.partials.hide-toggle-assets.
--}}
@php
    $names = $order->departments->whereIn('id', $row['hiddenFor'])->sortBy('name')->pluck('name')->implode(', ');
@endphp
<span class="badge bg-light text-muted border fw-normal hidden-badge {{ $names === '' ? 'd-none' : '' }} {{ $class ?? '' }}"
      @if($row['product']) data-hide-key="{{ $order->id }}-{{ $row['product']->id }}" @endif
      style="font-size:.65rem" title="Позиция скрыта в списке заявок для этих отделов">
    <i class="bi bi-eye-slash"></i> Скрыто: <span class="hidden-badge-names">{{ $names }}</span>
</span>
