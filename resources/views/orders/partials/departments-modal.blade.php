{{--
    Модалка выбора отделов заявки — одна на страницу. Адрес формы, заголовок и
    галочки подставляет скрипт ниже из data-атрибутов нажатой кнопки
    (orders.partials.departments-button).

    Параметры:
      $departments — Collection отделов на выбор
--}}
<div class="modal fade" id="order-departments-modal" tabindex="-1" aria-labelledby="order-departments-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="" class="modal-content" data-submit-guard>
            @csrf
            <div class="modal-header">
                <h5 class="modal-title" id="order-departments-title">Отделы заявки</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
            </div>
            <div class="modal-body">
                @forelse($departments as $dept)
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox"
                               name="departments[]" value="{{ $dept->id }}"
                               id="order-dept-{{ $dept->id }}">
                        <label class="form-check-label" for="order-dept-{{ $dept->id }}">{{ $dept->name }}</label>
                    </div>
                @empty
                    <p class="text-muted mb-0">Отделы не заведены.</p>
                @endforelse
                <div class="form-text mt-2">
                    Отделы записываются в МойСклад флажками-реквизитами с тем же именем.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> Сохранить
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('order-departments-modal');
    if (!modal) return;

    modal.addEventListener('show.bs.modal', function (e) {
        const btn = e.relatedTarget;
        if (!btn) return;

        const form     = modal.querySelector('form');
        const selected = JSON.parse(btn.dataset.departments || '[]').map(String);

        form.action = btn.dataset.action;
        modal.querySelector('.modal-title').textContent = 'Отделы заявки ' + (btn.dataset.name || '');
        form.querySelectorAll('input[name="departments[]"]').forEach(function (cb) {
            cb.checked = selected.includes(cb.value);
        });
    });
});
</script>
@endpush
