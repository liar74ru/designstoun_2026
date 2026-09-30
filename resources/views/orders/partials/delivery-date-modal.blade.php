{{--
    Модалка даты готовности заявки — одна на страницу. Адрес формы, заголовок и текущую
    дату подставляет скрипт ниже из data-атрибутов нажатой кнопки
    (data-bs-target="#order-delivery-date-modal", data-action, data-name, data-date в формате Y-m-d).
--}}
<div class="modal fade" id="order-delivery-date-modal" tabindex="-1" aria-labelledby="order-delivery-date-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <form method="POST" action="" class="modal-content" data-submit-guard>
            @csrf
            <div class="modal-header">
                <h5 class="modal-title" id="order-delivery-date-title">Дата готовности</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
            </div>
            <div class="modal-body">
                <label for="order-delivery-date" class="form-label small text-muted">Дата готовности</label>
                <input type="date" class="form-control" id="order-delivery-date" name="delivery_planned_at"
                       style="border-radius:.4rem" required>
                <div class="form-text mt-2">
                    @if(isset($order) && $order->isInternal())
                        Внутренний заказ есть только в программе — дата сохранится здесь.
                    @else
                        Дата уйдёт в МойСклад в поле «Планируемая дата отгрузки».
                    @endif
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
    const modal = document.getElementById('order-delivery-date-modal');
    if (!modal) return;

    modal.addEventListener('show.bs.modal', function (e) {
        const btn = e.relatedTarget;
        if (!btn) return;

        const form = modal.querySelector('form');
        form.action = btn.dataset.action;
        modal.querySelector('.modal-title').textContent = 'Дата готовности ' + (btn.dataset.name || '');
        form.querySelector('input[name="delivery_planned_at"]').value = btn.dataset.date || '';
    });
});
</script>
@endpush
