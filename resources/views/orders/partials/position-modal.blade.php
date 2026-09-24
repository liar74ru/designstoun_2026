{{--
    Настройка позиции заявки: склады комплектации и уточнения мастера.
    Одна модалка на страницу: формы лежат вне таблиц, поэтому нет ни вложенных <form>,
    ни дублирующихся полей между десктопным и мобильным деревом.
    Параметры: $order, $stores.
--}}
<div class="modal fade" id="positionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title">Позиция заявки</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
            </div>

            <form method="POST" action="{{ route('orders.position.update', $order->moysklad_id) }}" data-submit-guard>
                @csrf
                <input type="hidden" name="product_id" id="position_product_id">

                <div class="modal-body">
                    <div class="fw-semibold mb-2" id="position_name"></div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <label class="form-label fw-semibold mb-1">Склады комплектации</label>
                            {{-- Без name: галочка управляет только показом и в запрос не уходит --}}
                            <label class="d-flex align-items-center gap-1 m-0 text-muted"
                                   style="font-size:.78rem; cursor:pointer">
                                <input type="checkbox" class="form-check-input mt-0" id="position_all_stores">
                                Показать все склады
                            </label>
                        </div>
                        <div class="border rounded" style="max-height:200px; overflow-y:auto; border-radius:.4rem">
                            @foreach($stores as $store)
                                {{-- Граница у всех строк: последняя видимая определяется в JS,
                                     $loop->last после фильтрации уже не про неё --}}
                                <label class="d-flex align-items-center gap-2 px-2 py-1 m-0 position-store-row"
                                       style="cursor:pointer; border-bottom:1px solid #f1f3f5">
                                    <input type="checkbox" class="form-check-input mt-0 flex-shrink-0 position-store"
                                           name="stores[]" value="{{ $store->id }}">
                                    <span class="flex-grow-1" style="font-size:.84rem">{{ $store->name }}</span>
                                    <span class="text-muted position-store-qty"
                                          data-store-id="{{ $store->id }}"
                                          style="font-size:.78rem; font-variant-numeric: tabular-nums"></span>
                                </label>
                            @endforeach
                        </div>
                        @error('stores')<div class="text-danger" style="font-size:.8rem">{{ $message }}</div>@enderror
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label for="position_fact" class="form-label fw-semibold">Склад</label>
                            <input type="number" name="fact" id="position_fact"
                                   class="form-control text-center @error('fact') is-invalid @enderror"
                                   step="0.001" min="0">
                            @error('fact')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text" id="position_fact_hint"></div>
                        </div>
                        <div class="col-6">
                            <label for="position_produced" class="form-label fw-semibold">Изготовлено</label>
                            <input type="number" name="produced" id="position_produced"
                                   class="form-control text-center @error('produced') is-invalid @enderror"
                                   step="0.001" min="0">
                            @error('produced')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text" id="position_produced_hint"></div>
                        </div>
                    </div>

                    <div>
                        <label for="position_note" class="form-label">Примечание</label>
                        <input type="text" name="note" id="position_note" class="form-control"
                               maxlength="500" placeholder="Например: часть боя, пересчитали">
                    </div>
                </div>

                <div class="modal-footer py-2 d-flex justify-content-between">
                    {{-- Обёртка всегда на месте: иначе при скрытой кнопке justify-content-between схлопнет footer --}}
                    <div data-reset-url="{{ route('orders.position.destroy', [$order->moysklad_id, 0]) }}">
                        <button type="button" class="btn btn-outline-danger" id="position_reset_btn">
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

{{-- Сброс уточнений — отдельная форма: внутрь формы сохранения её вкладывать нельзя --}}
<form method="POST" id="positionResetForm" class="d-none">
    @csrf
    @method('DELETE')
</form>
