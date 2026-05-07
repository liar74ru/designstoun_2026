import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

document.addEventListener('focusin', (e) => {
    const el = e.target;
    if (!(el instanceof HTMLInputElement) || el.type !== 'number') return;
    if (el.dataset.zeroCleared) return;
    el.dataset.zeroCleared = '1';
    const v = el.value.trim();
    if (v === '' || parseFloat(v) === 0) {
        el.value = '';
        el.dispatchEvent(new Event('input', { bubbles: true }));
    }
});
