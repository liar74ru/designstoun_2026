{{-- Метка изменённой позиции: «было N» или «новая». Параметры: $change (из
     Order::position_changes или null), $fmtQty. --}}
@if($change)
    <span class="badge bg-warning-subtle text-warning-emphasis {{ $class ?? '' }}" style="font-size:.68rem"
          title="Изменено в МойСклад, ещё не принято">
        {{ (float) $change['from'] > 0 ? 'было ' . $fmtQty($change['from']) : 'новая' }}
    </span>
@endif
