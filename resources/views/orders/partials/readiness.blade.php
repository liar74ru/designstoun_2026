{{-- Готовность позиции: сколько из неотгруженного остатка лежит на производственном складе. --}}
@if($row['done'])
    <span class="badge bg-secondary-subtle text-secondary-emphasis">отгружено</span>
@elseif($row['prodQty'] === null)
    <span class="text-muted small">—</span>
@else
    @php
        $ready = $row['ready'] ?? 0;
        $barClass = $ready >= 1 ? 'bg-success' : ($ready > 0 ? 'bg-warning' : 'bg-danger');
    @endphp
    <div class="progress" style="height:5px">
        <div class="progress-bar {{ $barClass }}" style="width: {{ (int) round($ready * 100) }}%"></div>
    </div>
    <div class="text-muted mt-1" style="font-size:.72rem; font-variant-numeric: tabular-nums">
        @if($row['short'] > 0)
            <span class="text-danger">не хватает {{ $fmt1($row['short']) }}</span>
        @else
            хватает
        @endif
    </div>
@endif
