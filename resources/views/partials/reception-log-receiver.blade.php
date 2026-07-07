{{--
    Приёмщик записи журнала: select для админа (inline-правка атрибуции выработки),
    иначе — обычный текст. Ожидает: $log; опционально $editableReceiver (bool), $masterWorkers (Collection).
--}}
@php
    $editableReceiver = $editableReceiver ?? false;
    $masterWorkers    = $masterWorkers ?? collect();
@endphp
@if($editableReceiver && $masterWorkers->isNotEmpty())
    <select class="form-select form-select-sm js-log-receiver"
            data-action="{{ route('reception-logs.update-receiver', $log) }}"
            style="font-size:.75rem;padding:.1rem .35rem;border-radius:.4rem;width:auto;display:inline-block;min-width:120px">
        @foreach($masterWorkers as $mw)
            <option value="{{ $mw->id }}" {{ $log->receiver_id == $mw->id ? 'selected' : '' }}>{{ $mw->name }}</option>
        @endforeach
    </select>
@else
    {{ $log->receiver->name ?? '—' }}
@endif
