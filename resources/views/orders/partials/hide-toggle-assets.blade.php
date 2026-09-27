{{--
    Стили и скрипт скрытия позиции для отдела. Строка позиции — .order-pos с data-hide-key,
    внутри кнопка .hide-toggle (orders.partials.hide-toggle) и плашка .hidden-badge
    (orders.partials.hidden-badge). Стили нужны и списку заявок — там скрытые позиции
    видны только при «Показывать скрытые позиции».
--}}
@push('styles')
    <style>
        .order-pos.is-hidden > td,
        div.order-pos.is-hidden { opacity: .5; }
        .hide-toggle-btn { line-height: 1; font-size: .85rem; }
        .order-pos.is-hidden .hide-toggle-btn { color: #dc3545 !important; }
    </style>
@endpush

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Позиция нарисована дважды (десктоп и мобильная версия),
            // поэтому обновляем все элементы с тем же ключом.
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            document.addEventListener('click', function (e) {
                const item = e.target.closest('.hide-toggle-item');
                if (!item) return;

                const toggle = item.closest('.hide-toggle');
                const key = toggle.dataset.key;
                const hidden = item.dataset.hidden !== '1';
                item.disabled = true;

                fetch(toggle.dataset.url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ department_id: Number(item.dataset.departmentId), hidden: hidden }),
                })
                    .then(function (r) {
                        if (!r.ok) throw new Error(r.status);
                        return r.json();
                    })
                    .then(function (data) {
                        const hiddenFor = data.hiddenFor.map(String);
                        const names = JSON.parse(toggle.dataset.names);
                        const controlled = JSON.parse(toggle.dataset.controlled).map(String);

                        document.querySelectorAll('.hide-toggle[data-key="' + key + '"] .hide-toggle-item').forEach(function (el) {
                            const on = hiddenFor.includes(el.dataset.departmentId);
                            el.dataset.hidden = on ? '1' : '0';
                            el.querySelector('i').classList.toggle('invisible', !on);
                        });

                        // names — пары [id, название] в порядке сортировки, как в плашке с сервера
                        const label = names.filter(function (n) { return hiddenFor.includes(String(n[0])); })
                            .map(function (n) { return n[1]; }).join(', ');
                        document.querySelectorAll('.hidden-badge[data-hide-key="' + key + '"]').forEach(function (el) {
                            el.querySelector('.hidden-badge-names').textContent = label;
                            el.classList.toggle('d-none', label === '');
                        });

                        const rowHidden = controlled.every(function (id) { return hiddenFor.includes(id); });
                        document.querySelectorAll('.order-pos[data-hide-key="' + key + '"]').forEach(function (el) {
                            el.classList.toggle('is-hidden', rowHidden);
                        });
                    })
                    .catch(function () {
                        alert('Не удалось сохранить. Обновите страницу и попробуйте снова.');
                    })
                    .finally(function () { item.disabled = false; });
            });
        });
    </script>
@endpush
