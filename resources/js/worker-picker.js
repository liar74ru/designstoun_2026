function initWorkerPicker(select) {
    const toggleId   = select.dataset.toggleId;
    const toggle     = toggleId ? document.getElementById(toggleId) : null;

    // Отдел, по которому фильтруем: либо живое значение связанного селекта отдела
    // (data-dept-select-id), либо статический data-user-dept-id (фолбэк).
    const deptSelectId = select.dataset.deptSelectId;
    const deptSelect   = deptSelectId ? document.getElementById(deptSelectId) : null;

    function currentDept() {
        return deptSelect ? deptSelect.value : (select.dataset.userDeptId || '');
    }

    function apply() {
        const dept    = currentDept();
        const showAll = !dept || (toggle?.checked ?? false);
        Array.from(select.options).forEach(opt => {
            if (!opt.value) return;
            const optDept = opt.dataset.departmentId || '';
            // data-always-visible (например, администраторы) — не фильтруется по отделу
            const visible = showAll || opt.hasAttribute('data-always-visible')
                || String(optDept) === String(dept);
            opt.hidden = !visible;
            opt.disabled = !visible;
            if (!visible && opt.selected) {
                opt.selected = false;
                select.value = '';
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }

    const initialDept = currentDept();
    if (initialDept && select.value && toggle) {
        const selOpt = select.options[select.selectedIndex];
        if (selOpt && !selOpt.hasAttribute('data-always-visible')
            && String(selOpt.dataset.departmentId || '') !== String(initialDept)) {
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
