{{--
    Уточнение фактического остатка позиции заявки.
    Одна модалка на страницу: формы лежат вне таблиц, поэтому нет ни вложенных <form>,
    ни дублирующихся полей между десктопным и мобильным деревом.
    Параметры: $order, $productionStoreId.
--}}
<div class="modal fade" id="stockCorrectionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title">Фактический остаток</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
            </div>

            <form method="POST" action="{{ route('orders.corrections.store', $order->moysklad_id) }}" data-submit-guard>
                @csrf
                <input type="hidden" name="product_id" id="correction_product_id">
                <input type="hidden" name="store_id" value="{{ $productionStoreId }}">

                <div class="modal-body">
                    <div class="fw-semibold mb-2" id="correction_name"></div>

                    <div class="d-flex justify-content-between align-items-baseline mb-3 pb-2"
                         style="border-bottom:1px solid #f1f3f5">
                        <span class="text-muted" style="font-size:.78rem">В МойСклад</span>
                        <span id="correction_base" style="font-variant-numeric: tabular-nums"></span>
                    </div>

                    <div class="mb-3">
                        <label for="correction_fact" class="form-label fw-semibold">Фактически на складе</label>
                        <input type="number" name="fact" id="correction_fact"
                               class="form-control form-control-lg text-center @error('fact') is-invalid @enderror"
                               step="0.001" min="0" required>
                        @error('fact')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text" id="correction_hint"></div>
                    </div>

                    <div>
                        <label for="correction_note" class="form-label">Примечание</label>
                        <input type="text" name="note" id="correction_note" class="form-control"
                               maxlength="500" placeholder="Например: часть боя, пересчитали">
                    </div>
                </div>

                <div class="modal-footer py-2 d-flex justify-content-between">
                    {{-- Обёртка всегда на месте: иначе при скрытой кнопке justify-content-between схлопнет footer --}}
                    <div data-reset-url="{{ route('orders.corrections.destroy', [$order->moysklad_id, 0]) }}">
                        <button type="button" class="btn btn-outline-danger" id="correction_reset_btn">
                            Сбросить
                        </button>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" class="btn btn-primary">Сохранить</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Сброс уточнения — отдельная форма: внутрь формы сохранения её вкладывать нельзя --}}
<form method="POST" id="correctionResetForm" class="d-none">
    @csrf
    @method('DELETE')
    <input type="hidden" name="store_id" value="{{ $productionStoreId }}">
</form>
