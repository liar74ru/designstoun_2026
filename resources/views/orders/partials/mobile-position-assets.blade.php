{{-- Стили мобильной карточки позиции заявки (orders.partials.mobile-position). --}}
@push('styles')
    <style>
        .mpos {
            position: relative;
            display: flex;
            gap: .5rem;
            margin-bottom: .45rem;
            padding: .5rem .45rem .5rem .6rem;
            border: 1px solid #e9ecef;
            border-left: 4px solid var(--stone);
            border-radius: .5rem;
            background: var(--stone-bg, #fff);
            font-variant-numeric: tabular-nums;
        }
        .mpos-main { flex: 1; min-width: 0; }

        /* Название */
        .mpos-title { display: flex; gap: .35rem; align-items: flex-start; }
        .mpos-title ion-icon { flex-shrink: 0; margin-top: .15rem; font-size: 1rem; color: var(--stone); filter: brightness(.8); }
        .mpos-name { font-weight: 600; font-size: .86rem; line-height: 1.25; word-break: break-word; }
        .mpos-done { margin-top: .45rem; font-size: .74rem; color: #6c757d; }

        /* Формула «склад + изгот. = всего»: редактируемые слагаемые — плитки с рамкой */
        .mpos-formula { display: flex; align-items: stretch; gap: .25rem; margin-top: .45rem; }
        .mpos-op { align-self: center; font-size: 1rem; color: #adb5bd; }
        .mpos-tile {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: .2rem .4rem;
            border-radius: .4rem;
            line-height: 1.15;
        }
        .mpos-tile small { font-size: .6rem; color: #868e96; text-transform: uppercase; letter-spacing: .02em; }
        .mpos-tile .fw-semibold, .mpos-tile b { font-size: .95rem; color: #212529; }
        .mpos-tile.is-edit { border: 1px solid #ced4da; background: #fff; box-shadow: 0 1px 1px rgba(0, 0, 0, .05); }
        .mpos-tile.is-edit > span[role=button] { justify-content: flex-start !important; }
        .mpos-tile .bi-pencil-square { display: none; }
        .mpos-total { background: rgba(0, 0, 0, .05); }
        .mpos-total.is-ok { background: #dcf5e3; }
        .mpos-total.is-ok b { color: #15803d; }
        .mpos-total.is-bad { background: #fde2e4; }
        .mpos-total.is-bad b { color: #b4232f; }

        /* Полоса готовности и метка: ✓ — хватает, «−N» — нехватка */
        .mpos-status { display: flex; align-items: center; gap: .5rem; margin-top: .4rem; }
        .mpos-bar { flex: 1; height: 8px; border-radius: 4px; background: rgba(0, 0, 0, .08); overflow: hidden; }
        .mpos-bar span { display: block; height: 100%; }
        .mpos-ok {
            flex-shrink: 0;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #16a34a;
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .8rem;
        }
        .mpos-bad {
            flex-shrink: 0;
            padding: .05rem .45rem;
            border-radius: 999px;
            background: #dc3545;
            color: #fff;
            font-size: .74rem;
            font-weight: 700;
            line-height: 1.4;
        }

        /* Правая колонка: заказ сверху, действия внизу */
        .mpos-side {
            flex-shrink: 0;
            width: 64px;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding-left: .45rem;
            border-left: 1px solid rgba(0, 0, 0, .08);
        }
        .mpos-qty { display: flex; flex-direction: column; align-items: center; text-align: center; line-height: 1.05; }
        .mpos-qty .mpos-label {
            margin-bottom: .1rem;
            font-size: .58rem;
            font-weight: 400;
            color: #868e96;
            text-transform: uppercase;
            letter-spacing: .03em;
        }
        .mpos-qty b { font-size: 1.35rem; color: #212529; white-space: nowrap; letter-spacing: -.01em; }
        .mpos-qty b.is-md { font-size: 1.1rem; }
        .mpos-qty b.is-sm { font-size: .95rem; }
        .mpos-qty b .frac { font-size: .58em; font-weight: 600; vertical-align: .45em; }
        .mpos-qty small { font-size: .74rem; font-weight: 600; color: #495057; }
        .mpos-qty span { margin-top: .2rem; font-size: .62rem; color: #868e96; white-space: nowrap; }
        .mpos-actions { display: flex; gap: .25rem; margin-top: auto; padding-top: .4rem; }
        .mpos .ready-toggle { width: 26px; height: 26px; margin: 0; font-size: 1rem; border-width: 2px; }
        .mpos .hide-toggle-btn {
            width: 28px;
            height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: .4rem;
            background: rgba(0, 0, 0, .05);
            font-size: 1rem;
        }

        /* Отмеченная готовой: вместо общего затемнения .order-pos — зелёная рамка и уголок «✓ Готово» */
        div.mpos.is-ready {
            opacity: 1;
            padding-top: 1.25rem;
            border-color: #86d5a0;
            border-left-color: #16a34a;
            box-shadow: 0 0 0 1px #16a34a inset;
        }
        .mpos.is-ready::before {
            content: '✓ Готово';
            position: absolute;
            top: 0;
            left: 0;
            padding: .1rem .55rem;
            border-radius: .35rem 0 .45rem 0;
            background: #16a34a;
            color: #fff;
            font-size: .68rem;
            font-weight: 700;
            letter-spacing: .02em;
        }
    </style>
@endpush
