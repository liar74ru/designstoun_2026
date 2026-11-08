{{--
    Подпись дня в вертикальной шкале времени (список цеха, приёмок): «Сегодня», «Вчера»,
    «6 ноября, пятница». Ставится перед первой записью дня — записи идут сверху вниз, новые выше.
--}}
@props(['date', 'first' => false])
@php
    $label = match (true) {
        $date->isToday()     => 'Сегодня',
        $date->isYesterday() => 'Вчера',
        default              => $date->copy()->locale('ru')->translatedFormat('j F, l'),
    };
@endphp
<div class="d-flex align-items-center gap-2 {{ $first ? 'mb-2' : 'mt-3 mb-2' }}">
    <span class="fw-semibold text-secondary text-nowrap" style="font-size:.8rem">
        <i class="bi bi-calendar3 me-1"></i>{{ $label }}
        @unless($date->isToday() || $date->isYesterday())
            <span class="fw-normal text-muted ms-1">{{ $date->format('d.m.Y') }}</span>
        @endunless
    </span>
    <div class="flex-grow-1" style="border-top:1px solid #dee2e6"></div>
</div>
