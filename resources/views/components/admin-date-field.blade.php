@props([
    'label' => 'Дата создания',
    'hint'  => 'Оставьте пустым — дата установится автоматически',
    'value' => old('manual_created_at', ''),
])

@if(auth()->user()?->isAdmin())
<div class="mb-3 p-3 border border-warning rounded bg-warning bg-opacity-10">
    <label class="form-label fw-semibold text-warning-emphasis">
        <i class="bi bi-calendar-event"></i> {{ $label }}
        <span class="badge bg-warning text-dark ms-1" style="font-size:.7rem">Только для админа</span>
    </label>
    <input type="datetime-local"
           name="manual_created_at"
           class="form-control @error('manual_created_at') is-invalid @enderror"
           value="{{ $value }}">
    @error('manual_created_at')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
    <div class="form-text">{{ $hint }}</div>
</div>
@endif
