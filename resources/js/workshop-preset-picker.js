/**
 * workshop-preset-picker.js
 *
 * Выбор пресета цеха по группам: группы — карточками, по клику выпадает список её пресетов
 * с составом; под карточками — плашка выбранного пресета с кол-вом и «Применить».
 *
 * Данные — ответ api.department.workshop-presets: {groups: [{id, name, icon}], presets: [{id, name, color, group_id, items}]}.
 * Строки формы пикер не трогает: применение отдаётся колбэком onApply(preset, count).
 */

const NO_GROUP = 'none';
const HINT     = 'Заполнит сырьё, тару и продукт: количество = норма из шаблона × кол-во продукции. Текущие строки будут заменены.';

const esc = (v) => window.escapeHtml(v);

/** Цвет типа плитки: белый — «без цвета». */
function tint(color) {
    return color && color !== '#FFFFFF' ? color : null;
}

/** Количество без лишних нулей: 2.500 → «2,5». */
function qty(value) {
    return Number(value).toLocaleString('ru-RU', { maximumFractionDigits: 3 });
}

export class WorkshopPresetPicker {
    constructor(root, { onApply } = {}) {
        this.root       = root;
        this.onApply    = onApply || (() => {});
        this.sections   = [];
        this.byId       = {};
        this.selectedId = null;
        this.count      = '1';

        this.root.addEventListener('click', (e) => this.handleClick(e));
        this.root.addEventListener('input', (e) => {
            if (!e.target.matches('[data-preset-count]')) return;
            this.count = e.target.value;
        });

        this.render();
    }

    /** Новые данные (смена отдела) — выбор сбрасывается. Возвращает число пресетов. */
    setData(data) {
        const groups  = data?.groups || [];
        const presets = data?.presets || [];

        this.byId = {};
        presets.forEach(p => { this.byId[p.id] = p; });

        // Порядок групп — как пришёл с сервера (по имени), «Без группы» — последней.
        this.sections = groups.map(g => ({
            key: String(g.id), name: g.name, icon: g.icon || 'bi-folder',
            presets: presets.filter(p => p.group_id === g.id),
        }));
        this.sections.push({
            key: NO_GROUP, name: groups.length ? 'Без группы' : 'Все шаблоны',
            icon: groups.length ? 'bi-inbox' : 'bi-collection',
            presets: presets.filter(p => !groups.some(g => g.id === p.group_id)),
        });
        this.sections = this.sections.filter(s => s.presets.length);

        this.selectedId = null;
        this.render();

        return presets.length;
    }

    get selected() {
        return this.byId[this.selectedId] || null;
    }

    apply(preset) {
        if (!preset) return;
        const count = parseFloat(this.count) || 1; // пустое поле = 1
        this.onApply(preset, count);
    }

    handleClick(e) {
        if (e.target.closest('[data-preset-apply]')) {
            this.apply(this.selected);
            return;
        }

        const presetBtn = e.target.closest('[data-preset-id]');
        if (presetBtn) {
            this.selectedId = Number(presetBtn.dataset.presetId);
            this.render();
        }
    }

    render() {
        this.root.innerHTML = this.sections.length
            ? this.renderCards()
            : '<div class="text-muted small py-1">У отдела нет шаблонов.</div>';
    }

    presetStyle(preset) {
        const color = tint(preset.color);
        return color ? `border-left:4px solid ${color};background:${color}18` : 'border-left:4px solid transparent';
    }

    sectionOfSelected() {
        return this.sections.find(s => s.presets.some(p => p.id === this.selectedId)) || null;
    }

    countInput() {
        return `<input type="number" class="form-control form-control-sm" data-preset-count aria-label="Кол-во продукции"
                       style="border-radius:.4rem" step="0.001" min="0.001" value="${esc(this.count)}">`;
    }

    /** Состав пресета для подсказок: «Плитка ×1 · сырьё 2 · тара 1». */
    composition(preset) {
        const byRole = (role) => preset.items.filter(i => i.role === role);
        const products = byRole('product').map(i => `${esc(i.product_label)} ×${qty(i.quantity)}`);
        const parts = [`сырьё ${byRole('raw').length}`];
        if (byRole('package').length) parts.push(`тара ${byRole('package').length}`);
        return `${products.join(', ') || '—'} <span class="text-muted">· ${parts.join(' · ')}</span>`;
    }

    renderCards() {
        const sel    = this.selected;
        const active = this.sectionOfSelected();

        const cards = this.sections.map(section => {
            const isActive = active?.key === section.key;
            const items = section.presets.map(p => `
                <li>
                    <button type="button" class="dropdown-item wpp-rich ${p.id === this.selectedId ? 'active' : ''}"
                            data-preset-id="${p.id}" style="${this.presetStyle(p)}">
                        <div class="fw-semibold small text-wrap">${esc(p.name)}</div>
                        <div class="wpp-rich__sub text-wrap">${this.composition(p)}</div>
                    </button>
                </li>`).join('');

            return `
                <div class="col-6 col-sm-4 col-lg-3">
                    <div class="dropdown h-100">
                        <button type="button" class="wpp-gcard ${isActive ? 'is-active' : ''}"
                                data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="wpp-gcard__icon"><i class="bi ${esc(section.icon)}"></i></span>
                            <span class="wpp-gcard__name">${esc(section.name)}</span>
                            <span class="wpp-gcard__sub">${isActive ? esc(sel.name) : `шаблонов: ${section.presets.length}`}</span>
                        </button>
                        <ul class="dropdown-menu wpp-menu shadow">${items}</ul>
                    </div>
                </div>`;
        }).join('');

        const bar = sel ? `
            <div class="wpp-selbar mt-2" style="${this.presetStyle(sel)}">
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold small">${esc(sel.name)}</div>
                    <div class="wpp-rich__sub">${this.composition(sel)}</div>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <div style="width:6rem">${this.countInput()}</div>
                    <button type="button" class="btn btn-sm btn-primary" data-preset-apply>Применить</button>
                </div>
            </div>` : `
            <div class="small text-muted mt-2">Выберите группу и шаблон в ней.</div>`;

        return `<div class="row g-2">${cards}</div>${bar}
            <div class="form-text" style="font-size:.7rem">${HINT}</div>`;
    }
}

window.WorkshopPresetPicker = WorkshopPresetPicker;
