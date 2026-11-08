{{-- Десктоп: операции цеха на вертикальной шкале времени, новые сверху. --}}
@php $prevDay = null; @endphp
<div class="p-3">
    @foreach($workshops as $workshop)
        @php
            $day      = $workshop->created_at->toDateString();
            $dotColor = match ($workshop->status) {
                'active'    => 'var(--bs-success)',
                'completed' => 'var(--bs-warning)',
                default     => 'var(--bs-danger)',
            };
        @endphp
        @if($day !== $prevDay)
            <x-timeline-day :date="$workshop->created_at" :first="$loop->first" />
            @php $prevDay = $day; @endphp
        @endif
        <x-timeline-item :time="$workshop->created_at" :color="$dotColor">
            @include('workshops.partials.card', ['workshop' => $workshop])
        </x-timeline-item>
    @endforeach
</div>
