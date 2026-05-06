function initWorkerPicker(select) {
    const userDeptId = select.dataset.userDeptId || '';
    const toggleId   = select.dataset.toggleId;
    const toggle     = toggleId ? document.getElementById(toggleId) : null;

    function apply() {
        const showAll = !userDeptId || (toggle?.checked ?? false);
        Array.from(select.options).forEach(opt => {
            if (!opt.value) return;
            const dept = opt.dataset.departmentId || '';
            const visible = showAll || String(dept) === String(userDeptId);
            opt.hidden = !visible;
            opt.disabled = !visible;
            if (!visible && opt.selected) {
                opt.selected = false;
                select.value = '';
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }

    if (userDeptId && select.value && toggle) {
        const selOpt = select.options[select.selectedIndex];
        if (selOpt && String(selOpt.dataset.departmentId || '') !== String(userDeptId)) {
            toggle.checked = true;
        }
    }

    toggle?.addEventListener('change', apply);
    apply();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('select.worker-picker').forEach(initWorkerPicker);
});
