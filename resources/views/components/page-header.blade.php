{{--
    Заголовок страницы. $backUrl — родитель по умолчанию: кнопка «Назад» ведёт туда, откуда
    пришли в этой вкладке, а на родителя — при прямом заходе (resources/js/back-link.js).
--}}
@props([
    'title',
    'mobileTitle' => null,
    'backUrl'     => null,
    'backLabel'   => 'Назад',
    'hideMobile'  => false,
])

{{-- Десктоп --}}
<div class="d-none d-md-flex justify-content-between align-items-center mb-4">
    <h1 class="h2 mb-0">{{ $title }}</h1>
    <div class="d-flex gap-2 align-items-center">
        {{ $actions ?? '' }}
        @if($backUrl)
            <a href="{{ $backUrl }}" class="btn btn-outline-secondary" data-back>
                <i class="bi bi-arrow-left"></i> <span data-back-text>{{ $backLabel }}</span>
            </a>
        @endif
    </div>
</div>

@unless($hideMobile)
    {{-- Мобильный --}}
    <div class="d-flex d-md-none justify-content-between align-items-center mb-3">
        <h6 class="mb-0 fw-bold">{{ $mobileTitle ?? $title }}</h6>
        <div class="d-flex gap-1 align-items-center">
            {{ $mobileActions ?? '' }}
            @if($backUrl)
                <a href="{{ $backUrl }}" class="btn btn-outline-secondary btn-sm" data-back>
                    <i class="bi bi-arrow-left"></i>
                </a>
            @endif
        </div>
    </div>
@endunless
