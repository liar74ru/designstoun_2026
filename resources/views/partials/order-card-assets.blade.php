{{-- Стили мобильной карточки заявки в списке (partials.order-card, partials.order-card-row). --}}
@push('styles')
    <style>
        /* Шапка: номер и отделы мелко, клиент крупно, строка сроков и кнопки очереди */
        .ocard { overflow: hidden; font-variant-numeric: tabular-nums; }
        .ocard-head { padding: .55rem .65rem .4rem; }
        .ocard-num { font-weight: 700; font-size: .9rem; color: #6c757d; text-decoration: none; }
        .ocard-head .order-departments-btn .badge { font-weight: 500; }
        .ocard-head .dropdown-toggle { padding: .1rem .5rem; font-size: .74rem; border-radius: 999px; }
        .ocard-client { margin-top: .1rem; font-weight: 700; font-size: 1rem; line-height: 1.2; color: #212529; }
        .ocard-meta {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: .3rem;
            margin-top: .3rem;
            font-size: .74rem;
            color: #868e96;
        }
        .ocard-meta .bi-arrow-right { font-size: .65rem; }
        .ocard-due { padding: 0; border: 0; background: none; color: #1d4ed8; font-weight: 600; }
        .ocard-due .bi { font-size: .62rem; }
        .ocard-due.is-late { color: #dc3545; }

        /* Кнопки очереди — единая группа-переключатель */
        .ocard .order-priority { gap: 0 !important; padding: 2px; border-radius: .5rem; background: #e9ecef; }
        .ocard .priority-btn {
            width: 30px;
            height: 26px;
            border: 0;
            border-radius: .4rem;
            background: transparent;
            color: #343a40;
            font-size: .9rem;
        }
        .ocard .priority-btn:hover { background: #fff; }
        .ocard .priority-btn.is-urgent { background: #dc3545; color: #fff; }
        .ocard .priority-btn.is-manual { background: #0d6efd; color: #fff; }

        /* Сводка по позициям */
        .ocard-body { padding: 0 .65rem .45rem; }
        .ocard-summary {
            display: flex;
            justify-content: space-between;
            gap: .5rem;
            padding: .35rem .55rem;
            border-radius: .4rem;
            background: #fdecee;
            font-size: .78rem;
            color: #495057;
        }
        .ocard-summary b { color: #212529; }
        .ocard-summary.is-ok { background: #e6f6ec; color: #15803d; font-weight: 600; }
        .ocard-short { font-weight: 700; color: #dc3545; }
        .ocard-more summary { margin-top: .25rem; padding: .35rem 0; font-size: .74rem; color: #6c757d; cursor: pointer; }
        .ocard-note { display: block; padding: .4rem 0; font-size: .74rem; color: #868e96; text-decoration: none; }

        /* Позиция: фон — градиент цвета камня, итог справа на белом */
        .ocard-row {
            display: flex;
            align-items: center;
            gap: .5rem;
            margin-top: .25rem;
            padding: .4rem .5rem;
            border-radius: .45rem;
            background: linear-gradient(90deg, var(--stone-bg) 0%, var(--stone-soft) 45%, transparent 100%);
            box-shadow: inset 0 0 0 1px rgba(0, 0, 0, .04);
        }
        .ocard-row .ready-toggle { width: 20px; height: 20px; margin: 0; font-size: .75rem; }
        .ocard-spacer { width: 20px; flex-shrink: 0; }
        .ocard-main { flex: 1; min-width: 0; }
        .ocard-name { font-size: .82rem; line-height: 1.25; color: #343a40; word-break: break-word; }
        .ocard-sub { margin-top: .1rem; font-size: .7rem; color: #868e96; }
        .ocard-sub b { color: #495057; }
        .ocard-row.is-done .ocard-name { text-decoration: line-through; color: #adb5bd; }

        /* Метка справа: ✓ — хватает, «заказ / не хватает» — нехватка */
        .ocard-status { flex-shrink: 0; text-align: right; }
        .ocard-ok { font-size: 1.05rem; color: #16a34a; }
        .ocard-lack { font-size: .8rem; font-weight: 600; color: #16a34a; white-space: nowrap; }
        .ocard-lack .sep { color: #adb5bd; }
        .ocard-lack b { color: #dc3545; }
        .ocard-muted { font-size: .7rem; color: #adb5bd; }

        /* Отмеченная готовой: вместо общего затемнения .order-pos — зелёное название и «✓ Готово» */
        .ocard-ready { display: none; font-size: .74rem; font-weight: 700; color: #16a34a; white-space: nowrap; }
        div.ocard-row.is-ready { opacity: 1; }
        .ocard-row.is-ready .ocard-state { display: none; }
        .ocard-row.is-ready .ocard-ready { display: inline; }
        .ocard-row.is-ready .ocard-name { color: #15803d; }

        /* Краткий вид (по умолчанию): первая строка и незакрытые позиции; шеврон разворачивает */
        .ocard.is-compact .ocard-client,
        .ocard.is-compact .ocard-meta,
        .ocard.is-compact .ocard-summary,
        .ocard.is-compact .ocard-more,
        .ocard.is-compact .ocard-sub,
        .ocard.is-compact .ocard-note { display: none; }
        .ocard.is-compact .ocard-head { padding-bottom: .45rem; }
        .ocard.is-compact .ocard-row:first-child { margin-top: 0; }
        .ocard-toggle {
            width: 28px;
            height: 28px;
            flex-shrink: 0;
            padding: 0;
            border: 0;
            border-radius: 50%;
            background: #f1f3f5;
            color: #495057;
        }
        .ocard-toggle .bi { display: inline-block; transition: transform .15s; }
        .ocard:not(.is-compact) .ocard-toggle .bi { transform: rotate(180deg); }
    </style>
@endpush
