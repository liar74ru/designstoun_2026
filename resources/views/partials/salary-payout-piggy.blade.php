{{-- Поле «Копилка ±» форм выдачи. На сервер не уходит: копилка считается от выданного. Параметр: $id. --}}
<div class="d-flex justify-content-between align-items-center gap-2 mb-1 border-top pt-1 mt-1">
    <label class="small mb-0" for="{{ $id }}">
        Копилка ±
        <span class="d-block text-muted" style="font-size:.7rem">+ положить ещё, − взять из копилки</span>
    </label>
    <div class="input-group input-group-sm" style="width:9rem">
        <input type="number" id="{{ $id }}" step="0.01" value="0" data-payout-piggy
               class="form-control text-end">
        <span class="input-group-text">₽</span>
    </div>
</div>
