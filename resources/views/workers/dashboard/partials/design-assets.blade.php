{{--
    Мобильный вид «Моей выработки» (< 768px; десктоп не меняется). Выбран в 4.1.1 из вариантов:
    блоки сливались — и между собой, и разделы внутри (выдача, «Исправить последнюю выдачу»,
    «История»): глобальный .card (custom.css) без рамки, с градиентом почти в цвет фона.

    Блок — рамка и шапка с цветной заливкой (копилка — зелёная, ремонт — оранжевая, период —
    синяя); тело — серая подложка, каждый раздел внутри — отдельная белая карточка с рамкой;
    раскрывающиеся разделы — строки с шевроном. Сводку по продуктам и детализацию не трогает.

    Хуки разметки: блоки — .wd-section--salary / --period / --hours, итоги — .wd-tiles;
    разделы внутри блока — .wd-part (--note — пояснение, --payout — выдача, --more — раскрывающийся
    раздел <details>, --list — список часов).
--}}
@push('styles')
<style>
@media (max-width: 767.98px) {
    .wd :is(.wd-section--salary, .wd-section--period, .wd-section--hours, .wd-tiles .card) { animation: none; }

    /* Плашка «Выбор с …» не влезала в строку заголовка копилки — переносится, прижата вправо */
    .wd #salary-toggle > .d-flex:first-child { flex-wrap: wrap; row-gap: .3rem; }
    .wd #salary-toggle > .d-flex:first-child > :last-child { margin-left: auto; }

    /* Блоки: рамка, серая подложка, шапка с цветной заливкой */
    .wd :is(.wd-section--salary, .wd-section--period, .wd-section--hours) {
        --wd-tint: #eef1f4; --wd-line: #cfd5dc;
        background: #f3f5f7 !important;
        border: 1px solid #c9d0d7 !important;
        border-radius: .85rem !important;
        box-shadow: 0 1px 4px rgba(16, 24, 40, .08) !important;
        margin-bottom: 1.25rem !important;
        overflow: hidden;
    }
    .wd .wd-section--salary { --wd-tint: #e6f4ec; --wd-line: #a8d5b9; }
    .wd .wd-section--period { --wd-tint: #e7f0ff; --wd-line: #a9c6f5; }
    .wd .wd-section--hours  { --wd-tint: #fff1e4; --wd-line: #f5c79c; }
    .wd :is(.wd-section--salary, .wd-section--period, .wd-section--hours) > .card-header {
        background: var(--wd-tint) !important;
        border-bottom: 1px solid var(--wd-line) !important;
    }

    /* Разделы внутри блока — белые карточки с рамкой и промежутком */
    .wd .wd-section .wd-part {
        background: #fff !important;
        border: 1px solid #dde2e7 !important;
        border-radius: .6rem !important;
        padding: .6rem .75rem !important;
        margin: 0 0 .55rem !important;
    }
    .wd .wd-section--salary > .card-body { padding: .6rem !important; background: transparent; }
    .wd .wd-section--period .card-body.wd-part { margin: .6rem !important; }
    .wd .wd-section--hours .wd-part { margin: .6rem !important; }
    /* Свёрнутый блок часов — только шапка, без линии под ней */
    .wd .wd-section--hours > .card-header[aria-expanded="false"] { border-bottom: none !important; }
    .wd .wd-section--hours .wd-part--list { padding: 0 !important; overflow: hidden; }
    .wd .wd-section--hours .wd-part--list .list-group-item { border-color: #e3e7eb; }
    .wd .wd-part--note { background: transparent !important; border-style: dashed !important; }

    /* Раскрывающийся раздел — строка с шевроном справа, без треугольника */
    .wd .wd-part--more > summary {
        list-style: none;
        display: flex;
        align-items: center;
        gap: .4rem;
        color: #212529 !important;
        font-size: .9rem !important;
        font-weight: 600;
    }
    .wd .wd-part--more > summary::-webkit-details-marker { display: none; }
    .wd .wd-part--more > summary::after {
        content: "\F282"; /* bi-chevron-down */
        font-family: "bootstrap-icons";
        font-weight: normal;
        color: #6c757d;
        margin-left: auto;
        transition: transform .15s;
    }
    .wd .wd-part--more[open] > summary::after { transform: rotate(180deg); }
    .wd .wd-part--more > summary.text-primary { color: #0d6efd !important; }

    /* Итоги периода — карточки с рамкой, как блоки */
    .wd .wd-tiles .card {
        background: #fff !important;
        border: 1px solid #c9d0d7 !important;
        border-radius: .85rem !important;
    }
    .wd .wd-tiles { margin-bottom: 1.25rem !important; }
}
</style>
@endpush
