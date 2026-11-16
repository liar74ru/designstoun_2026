{{--
    Скрипт панели «Коэффициенты» из partials/production-document/products: показать/скрыть
    панель и пересчитать итог строки на лету. Область правил и SKU партии берутся из
    data-атрибутов .modifier-picker, поэтому код один для приёмки и цеха.
    Подключать после partials.production-rates-js (нужны RateFormula и ModifierPicker).
--}}
<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggleBtn = document.getElementById('toggleCoeffEdit');
    const cancelBtn = document.getElementById('cancelCoeffEdit');
    const editPanel = document.getElementById('coeffEditPanel');

    if (!toggleBtn || !editPanel) return;

    function showEdit() {
        editPanel.style.display = '';
        toggleBtn.classList.replace('btn-outline-warning', 'btn-warning');
    }
    function hideEdit() {
        editPanel.style.display = 'none';
        toggleBtn.classList.replace('btn-warning', 'btn-outline-warning');
    }

    toggleBtn.addEventListener('click', function () {
        editPanel.style.display === 'none' ? showEdit() : hideEdit();
    });
    cancelBtn?.addEventListener('click', hideEdit);

    function recalcRow(rowIdx) {
        const baseInput = document.querySelector(`.coeff-base-input[data-row="${rowIdx}"]`);
        const picker    = document.querySelector(`.modifier-picker[data-row="${rowIdx}"]`);
        const display   = document.querySelector(`.coeff-result-display[data-row="${rowIdx}"]`);
        if (!baseInput || !display) return;

        const base = parseFloat(baseInput.value) || 0;

        // Ровно то же, что считает сервер (ProductionDocumentService::updateItemCoeff):
        // база из формы плюс отмеченные правила; уже применённые — со снапшотными числами.
        const ctx = {
            applied: picker
                ? RateFormula.resolveKeepingSnapshot({
                    departmentId: picker.dataset.departmentId || null,
                    scope: picker.dataset.scope || 'reception',
                    sku: picker.dataset.sku || null,
                    submittedKeys: ModifierPicker.submittedKeys(picker),
                    batchSku: picker.dataset.batchSku || null,
                    snapshot: ModifierPicker.snapshotOf(picker),
                })
                : [],
        };
        const effective = RateFormula.effectiveCoeff({ ...ctx, baseCoeff: base });

        display.textContent = RateFormula.formatCoeff(effective);
        display.className   = display.className.replace(/bg-\w+/, effective < base ? 'bg-warning' : 'bg-secondary');

        const masterInput   = document.querySelector(`.coeff-master-base-input[data-row="${rowIdx}"]`);
        const masterDisplay = document.querySelector(`.coeff-master-result-display[data-row="${rowIdx}"]`);
        if (!masterInput || !masterDisplay) return;

        const masterBase = parseFloat(masterInput.value) || 0;
        const masterEff  = RateFormula.masterEffectiveCoeff({ ...ctx, baseCoeff: masterBase });

        masterDisplay.textContent = RateFormula.formatCoeff(masterEff);
        masterDisplay.className   = masterDisplay.className.replace(/bg-\w+/, masterEff < masterBase ? 'bg-warning' : 'bg-secondary');
    }

    document.querySelectorAll('.coeff-base-input, .coeff-master-base-input').forEach(el => {
        el.addEventListener('input', () => recalcRow(el.dataset.row));
    });
    document.addEventListener('change', function (e) {
        if (!e.target.classList.contains('modifier-checkbox')) return;
        const picker = e.target.closest('.modifier-picker');
        if (picker) recalcRow(picker.dataset.row);
    });

    // Итог считается по отмеченным чекбоксам, а их рисует modifier-picker на том же
    // DOMContentLoaded, но позже (модуль app.js): дорисовываем сами, иначе первый итог — без правил.
    document.querySelectorAll('.modifier-picker').forEach(picker => {
        if (!picker.dataset.rendered) ModifierPicker.render(picker);
    });
    document.querySelectorAll('.coeff-base-input').forEach(el => recalcRow(el.dataset.row));
});
</script>
