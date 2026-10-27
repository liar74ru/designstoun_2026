{{-- Раскладка выданной зарплаты: карта и налог — только если заполнены. Параметр: $payout. --}}
@php $rub = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' ₽'; @endphp
<div class="text-muted">
    на руки {{ $rub($payout->paid_cash) }}
    @if($payout->paid_card) · на карту {{ $rub($payout->paid_card) }} @endif
    @if($payout->paid_tax) · налог {{ $rub($payout->paid_tax) }} @endif
</div>
@if($payout->comment)
    <div class="text-muted fst-italic">{{ $payout->comment }}</div>
@endif
