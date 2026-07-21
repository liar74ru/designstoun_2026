function parseIds(value) {
    return String(value || '')
        .split(',')
        .map(id => id.trim())
        .filter(Boolean);
}

function initWorkerPicker(select) {
    const toggleId   = select.dataset.toggleId;
    const toggle     = toggleId ? document.getElementById(toggleId) : null;

    // Отделы, по которым фильтруем: либо живое значение связанного селекта отдела
    // (data-dept-select-id), либо статический список отделов пользователя
    // (data-user-dept-ids, работник может состоять в нескольких отделах).
    const deptSelectId = select.dataset.deptSelectId;
    const deptSelect   = deptSelectId ? document.getElementById(deptSelectId) : null;

    function currentDepts() {
        return deptSelect
            ? parseIds(deptSelect.value)
            : parseIds(select.dataset.userDeptIds);
    }

    function inDepts(opt, depts) {
        return parseIds(opt.dataset.departmentIds).some(id => depts.includes(id));
    }

    function apply() {
        const depts   = currentDepts();
        const showAll = depts.length === 0 || (toggle?.checked ?? false);
        Array.from(select.options).forEach(opt => {
            if (!opt.value) return;
            // data-always-visible (например, администраторы) — не фильтруется по отделу
            const visible = showAll || opt.hasAttribute('data-always-visible')
                || inDepts(opt, depts);
            opt.hidden = !visible;
            opt.disabled = !visible;
            if (!visible && opt.selected) {
                opt.selected = false;
                select.value = '';
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }

    const initialDepts = currentDepts();
    if (initialDepts.length && select.value && toggle) {
        const selOpt = select.options[select.selectedIndex];
        if (selOpt && !selOpt.hasAttribute('data-always-visible')
            && !inDepts(selOpt, initialDepts)) {
            toggle.checked = true;
        }
    }

    toggle?.addEventListener('change', apply);
    deptSelect?.addEventListener('change', apply);
    apply();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('select.worker-picker').forEach(initWorkerPicker);
});
