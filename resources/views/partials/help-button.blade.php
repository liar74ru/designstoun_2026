{{--
    Кнопка «?» — справка по разделу (help.show). Рисуется, только если страница доступна.
    Параметры: $page — ключ HelpService::PAGES, $class — классы кнопки (по умолчанию btn-outline-secondary).
--}}
@if(app(\App\Services\HelpService::class)->canSee(auth()->user(), $page))
    <a href="{{ route('help.show', $page) }}"
       class="btn {{ $class ?? 'btn-outline-secondary' }}"
       title="Справка: {{ \App\Services\HelpService::PAGES[$page]['title'] }}">
        <i class="bi bi-question-circle"></i>
    </a>
@endif
