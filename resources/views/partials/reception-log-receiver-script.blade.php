{{-- Inline-правка приёмщика в записи журнала (admin). Подключать один раз на странице. --}}
<script>
    document.addEventListener('change', function (e) {
        const sel = e.target.closest('.js-log-receiver');
        if (!sel) return;

        const action    = sel.dataset.action;
        const csrfToken  = document.querySelector('meta[name="csrf-token"]')?.content;
        const prevValue  = sel.dataset.prevValue ?? '';
        sel.disabled = true;

        fetch(action, {
            method: 'PATCH',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `receiver_id=${encodeURIComponent(sel.value)}`,
        })
            .then(r => r.ok ? r.json() : Promise.reject(r))
            .then(data => {
                if (!data.success) throw new Error();
                sel.dataset.prevValue = sel.value;
                sel.classList.add('border-success');
                setTimeout(() => sel.classList.remove('border-success'), 1200);
            })
            .catch(() => {
                if (prevValue) sel.value = prevValue;
                alert('Не удалось сменить приёмщика');
            })
            .finally(() => { sel.disabled = false; });
    });

    // Запоминаем исходное значение для отката при ошибке
    document.querySelectorAll('.js-log-receiver').forEach(sel => {
        sel.dataset.prevValue = sel.value;
    });
</script>
