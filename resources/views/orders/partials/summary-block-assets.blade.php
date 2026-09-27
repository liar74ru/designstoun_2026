{{-- Стили блока «Заявка» в карточке заявки (orders.partials.summary-block). --}}
@push('styles')
    <style>
        .osum { font-variant-numeric: tabular-nums; }
        .osum-label {
            margin-bottom: .1rem;
            font-size: .66rem;
            color: #868e96;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .osum-client { font-weight: 700; font-size: 1.05rem; line-height: 1.2; }
        .osum-chip {
            padding: .15rem .6rem;
            border: 1px solid #dee2e6;
            border-radius: 999px;
            background: #fff;
            color: #495057;
            font-size: .78rem;
        }

        /* Плитки: даты / срочно и итоги */
        .osum-tiles { display: grid; grid-template-columns: repeat(3, 1fr); gap: .35rem; margin-top: .6rem; }
        .osum-tiles > div { padding: .35rem .5rem; border-radius: .45rem; background: #f1f3f5; line-height: 1.15; }
        /* Плитка «готовность» — кнопка смены даты (модалка orders.partials.delivery-date-modal) */
        .osum-due-btn {
            padding: .35rem .5rem;
            border: 1px dashed #ced4da;
            border-radius: .45rem;
            background: #fff;
            line-height: 1.15;
            text-align: left;
            color: inherit;
        }
        .osum-due-btn:hover { border-color: #1d4ed8; }
        .osum-due-btn small .bi { font-size: .7rem; }
        .osum-tiles small,
        .osum-urgent small {
            display: block;
            font-size: .62rem;
            color: #868e96;
            text-transform: uppercase;
            letter-spacing: .03em;
        }
        .osum-tiles b { font-size: 1.15rem; }
        .osum-tiles .is-bad { background: #fde2e4; }
        .osum-tiles .is-bad b { color: #b4232f; }
        .osum-tiles .is-ok { background: #dcf5e3; }
        .osum-tiles .is-ok b { color: #15803d; font-size: .95rem; }
        .osum-dates b { font-size: .95rem; }
        .osum-due { display: block; margin-top: .1rem; font-size: .66rem; }

        /* Переключатель «срочно» — плитка целиком */
        .osum-urgent {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            justify-content: space-between;
            padding: .35rem .5rem;
            border: 0;
            border-radius: .45rem;
            background: #f1f3f5;
            line-height: 1.15;
            text-align: left;
        }
        .osum-switch {
            position: relative;
            display: inline-block;
            width: 34px;
            height: 18px;
            margin-top: .2rem;
            border-radius: 999px;
            background: #ced4da;
            transition: background .15s;
        }
        .osum-switch span {
            position: absolute;
            top: 2px;
            left: 2px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: #fff;
            transition: left .15s;
        }
        .osum-urgent.is-on { background: #fde2e4; }
        .osum-urgent.is-on small { color: #b4232f; font-weight: 700; }
        .osum-urgent.is-on .osum-switch { background: #dc3545; }
        .osum-urgent.is-on .osum-switch span { left: 18px; }

        /* Полоса готовности */
        .osum-ready { margin-top: .6rem; }
        .osum-ready b { font-size: 1rem; }
        .osum-bar { height: 10px; margin-top: .25rem; border-radius: 5px; background: #e9ecef; overflow: hidden; }
        .osum-bar span { display: block; height: 100%; }

        /* Отделы, производство, пересчёт */
        .osum-departments {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: .5rem;
            margin-top: .65rem;
            padding-top: .6rem;
            border-top: 1px solid #f1f3f5;
        }
        .osum-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: .5rem;
            margin-top: .5rem;
            font-size: .74rem;
            color: #6c757d;
        }
        .osum-footer .btn { font-size: .74rem; }
    </style>
@endpush
