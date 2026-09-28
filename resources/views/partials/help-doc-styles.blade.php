{{-- Оформление текста справки (.help-doc) — markdown из docs/, отрендеренный HelpService. --}}
@push('styles')
    <style>
        .help-doc { max-width: 60rem; }
        .help-doc h1 { font-size: 1.5rem; margin-bottom: 1rem; }
        .help-doc h2 { font-size: 1.2rem; margin-top: 2rem; padding-bottom: .3rem; border-bottom: 1px solid var(--bs-border-color); }
        .help-doc h3 { font-size: 1rem; margin-top: 1.25rem; }
        .help-doc hr { display: none; }
        .help-doc ul, .help-doc ol { padding-left: 1.25rem; }
        .help-doc li { margin-bottom: .3rem; }
        .help-doc table { width: 100%; margin-bottom: 1rem; font-size: .9rem; }
        .help-doc th, .help-doc td { padding: .4rem .5rem; border-bottom: 1px solid var(--bs-border-color); vertical-align: top; }
        .help-doc th { background: var(--bs-tertiary-bg); white-space: nowrap; }
        .help-doc code { color: inherit; background: var(--bs-tertiary-bg); padding: 0 .25rem; border-radius: .2rem; }
        .help-doc blockquote { border-left: 3px solid var(--bs-primary); padding: .25rem .75rem; margin: 0 0 1rem; background: var(--bs-tertiary-bg); }
        .help-doc blockquote p:last-child { margin-bottom: 0; }

        /* Ссылки: справка — со значком книги, экран программы — со стрелкой «↗».
           Различаем по href: HelpService ведёт справку на /help/<page>. */
        .help-doc a {
            color: var(--bs-primary);
            font-weight: 500;
            text-decoration: none;
            background: rgba(var(--bs-primary-rgb), .08);
            padding: 0 .25rem;
            border-radius: .25rem;
        }
        .help-doc a:hover { background: rgba(var(--bs-primary-rgb), .16); text-decoration: underline; }
        .help-doc a[href*="/help/"]::before {
            content: "\f194"; /* bi-book */
            font-family: bootstrap-icons;
            font-weight: normal;
            font-size: .85em;
            margin-right: .25rem;
            vertical-align: -.05em;
        }
        .help-doc a:not([href*="/help/"]):not([href^="#"])::after { content: "\2197"; margin-left: .15rem; font-size: .85em; }
        @media (max-width: 767.98px) {
            .help-doc { padding: .75rem; font-size: .92rem; }
            .help-doc table { display: block; overflow-x: auto; }
        }
    </style>
@endpush
