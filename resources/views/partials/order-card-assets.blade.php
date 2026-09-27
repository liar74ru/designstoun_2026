{{-- Стили мобильной карточки заявки в списке (partials.order-card). --}}
@push('styles')
    <style>
        /* Шапка */
        .ocard { overflow: hidden; font-variant-numeric: tabular-nums; }
        .ocard-head { padding: .5rem .6rem .45rem; border-bottom: 1px solid #f1f3f5; }
        .ocard-num { font-weight: 700; font-size: .95rem; color: #212529; text-decoration: none; }
        .ocard-num .bi { font-size: .7rem; color: #adb5bd; }
        .ocard-client { font-weight: 600; font-size: .92rem; line-height: 1.2; }

        /* Чипы дат: дата готовности светло-синяя, просроченная — красная; по нажатию — модалка смены даты */
        .ocard-chips { display: flex; flex-wrap: wrap; gap: .3rem; margin-top: .3rem; }
        .ocard-chip {
            display: inline-flex;
            align-items: center;
            gap: .25rem;
            padding: .1rem .5rem;
            border-radius: 999px;
            background: #f1f3f5;
            color: #495057;
            font-size: .74rem;
            white-space: nowrap;
        }
        .ocard-chip .bi { font-size: .68rem; color: #868e96; }
        .ocard-due { border: 0; font-weight: 600; background: #e7f1ff; color: #1d4ed8; }
        .ocard-due .bi { color: inherit; }
        .ocard-due.is-late { background: #dc3545; color: #fff; }

        /* Позиции */
        .ocard-body { padding: .4rem .4rem .45rem; }
        .ocard-pos {
            display: flex;
            align-items: center;
            gap: .45rem;
            margin-bottom: .3rem;
            padding: .35rem .45rem;
            border-left: 3px solid var(--stone);
            border-radius: .4rem;
            background: var(--stone-bg, #f8f9fa);
        }
        .ocard-pos .ready-toggle { width: 24px; height: 24px; margin: 0; font-size: .85rem; border-width: 2px; }
        .ocard-spacer { width: 24px; flex-shrink: 0; }
        .ocard-main { flex: 1; min-width: 0; }
        .ocard-name { font-size: .8rem; font-weight: 600; line-height: 1.25; word-break: break-word; }
        .ocard-pos.is-done .ocard-name { text-decoration: line-through; color: #868e96; }

        /* Формула «склад + изгот. = всего / заказ» */
        .ocard-formula {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: .3rem;
            margin-top: .15rem;
            font-size: .72rem;
            color: #6c757d;
        }
        .ocard-formula .bi { font-size: .68rem; }
        .ocard-formula b { color: #212529; }
        .ocard-formula .op { color: #adb5bd; }

        /* Метка справа: ✓ — хватает, «−N» — нехватка */
        .ocard-status { display: flex; flex-direction: column; align-items: flex-end; line-height: 1.1; }
        .ocard-shipped { font-size: .68rem; color: #6c757d; }
        .ocard-ok {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #16a34a;
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .85rem;
        }
        .ocard-bad {
            padding: .05rem .45rem;
            border-radius: 999px;
            background: #dc3545;
            color: #fff;
            font-size: .74rem;
            font-weight: 700;
            line-height: 1.4;
            white-space: nowrap;
        }

        /* Отмеченная готовой: вместо общего затемнения .order-pos — зелёная рамка и плашка «✓ Готово» */
        .ocard-ready-pill { display: none; }
        div.ocard-pos.is-ready { opacity: 1; border-left-color: #16a34a; box-shadow: 0 0 0 1.5px #16a34a inset; }
        .ocard-pos.is-ready .ocard-status { display: none; }
        .ocard-pos.is-ready .ocard-ready-pill {
            display: inline-flex;
            align-items: center;
            gap: .15rem;
            padding: .1rem .5rem;
            border-radius: 999px;
            background: #16a34a;
            color: #fff;
            font-size: .72rem;
            font-weight: 700;
            white-space: nowrap;
        }

        /* Низ карточки */
        .ocard-hidden { display: block; margin: .1rem .2rem .2rem; font-size: .74rem; color: #6c757d; text-decoration: none; }
        .ocard-foot { display: flex; align-items: center; gap: .4rem; margin: .35rem .2rem 0; }
        .ocard-label { font-size: .62rem; color: #868e96; text-transform: uppercase; letter-spacing: .04em; }
    </style>
@endpush
