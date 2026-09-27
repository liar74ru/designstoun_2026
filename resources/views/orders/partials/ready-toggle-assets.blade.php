{{--
    Стили и скрипт ручной отметки «позиция готова» — для списка и карточки заявки.
    Позиция помечается .order-pos с data-position, внутри — кнопка .ready-toggle
    (orders.partials.ready-toggle), число «Всего» в .total-value и плашка .ready-badge.
--}}
@push('styles')
    <style>
        /* Кнопка ручной отметки «готово» */
        .ready-toggle {
            width: 18px;
            height: 18px;
            margin-top: 2px;
            padding: 0;
            border: 1px solid #ced4da;
            border-radius: 50%;
            background: #fff;
            color: #ced4da;
            font-size: .7rem;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .ready-toggle:hover { color: #16a34a; border-color: #16a34a; }
        .ready-toggle[disabled] { opacity: .5; cursor: wait; }
        .order-pos.is-ready .ready-toggle {
            background: #16a34a;
            border-color: #16a34a;
            color: #fff;
        }

        /* Готовая позиция: приглушённая строка, вместо «Всего» — плашка «готово» */
        .ready-badge { display: none; }
        .order-pos.is-ready > td,
        div.order-pos.is-ready { opacity: .55; }
        .order-pos.is-ready .total-value { display: none; }
        .order-pos.is-ready .ready-badge { display: inline-block; }
    </style>
@endpush

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Позиция нарисована дважды (десктоп и мобильная версия),
            // поэтому перекрашиваем все элементы с тем же data-position.
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            document.addEventListener('click', function (e) {
                const btn = e.target.closest('.ready-toggle');
                if (!btn) return;

                const row = btn.closest('.order-pos');
                const ready = !row.classList.contains('is-ready');
                btn.disabled = true;

                fetch(btn.dataset.url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ ready: ready }),
                })
                    .then(function (r) {
                        if (!r.ok) throw new Error(r.status);
                        return r.json();
                    })
                    .then(function (data) {
                        document.querySelectorAll('.order-pos[data-position="' + row.dataset.position + '"]')
                            .forEach(function (el) { el.classList.toggle('is-ready', data.ready); });
                    })
                    .catch(function () {
                        alert('Не удалось сохранить отметку. Обновите страницу и попробуйте снова.');
                    })
                    .finally(function () { btn.disabled = false; });
            });
        });
    </script>
@endpush
