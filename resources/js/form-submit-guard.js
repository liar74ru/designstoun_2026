// Блокировка формы на время сохранения.
// Форма помечается атрибутом data-submit-guard: при отправке активная кнопка
// получает спиннер «Сохранение…», все submit-кнопки блокируются, повторная
// отправка гасится. Возврат по «Назад» из bfcache восстанавливает кнопки такими,
// какими они были до отправки: заблокированная проверкой формы остаётся заблокированной.
// Своя подпись спиннера — значением атрибута: data-submit-guard="Выполняется…".
//
// Форма с атрибутом data-confirm="текст" спрашивает подтверждение перед отправкой. Текст может
// содержать данные: в отличие от onsubmit="confirm('{{ $x }}')", он не выполняется как код.
// Перехват на всплытии не подходит: обработчик самой формы успел бы её отправить.
document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || form.dataset.confirm === undefined) return;
    if (form.dataset.submitting === '1') return;
    if (!window.confirm(form.dataset.confirm)) {
        e.preventDefault();
        e.stopPropagation();
    }
}, true);

document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-submit-guard')) return;
    if (form.dataset.submitting === '1') { e.preventDefault(); return; }
    if (e.defaultPrevented) return; // валидация или confirm() отменили отправку

    form.dataset.submitting = '1';
    const buttons = form.querySelectorAll('button[type="submit"]');
    const active  = (e.submitter && e.submitter.matches('button[type="submit"]')) ? e.submitter : buttons[0];
    buttons.forEach(b => {
        b.dataset.guardHtml = b.innerHTML;
        b.dataset.guardDisabled = b.disabled ? '1' : '';
    });
    const label = form.dataset.submitGuard || 'Сохранение…';
    if (active) {
        active.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> ';
        active.append(label); // подпись — текстом, не разметкой
    }
    // Блокируем отложенно — иначе name/value кнопки-сабмиттера (например close_workshop=1) не попадут в запрос
    setTimeout(() => buttons.forEach(b => { b.disabled = true; }), 0);
});

window.addEventListener('pageshow', (e) => {
    if (!e.persisted) return;
    document.querySelectorAll('form[data-submit-guard]').forEach(form => {
        delete form.dataset.submitting;
        form.querySelectorAll('button[type="submit"]').forEach(b => {
            if (b.dataset.guardHtml === undefined) return;
            b.disabled = b.dataset.guardDisabled === '1';
            b.innerHTML = b.dataset.guardHtml;
            delete b.dataset.guardHtml;
            delete b.dataset.guardDisabled;
        });
    });
});
