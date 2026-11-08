{{-- Бейджи статуса и синхронизации операции цеха. Параметры: $workshop, $font (по умолчанию .65rem). --}}
@php $font = $font ?? '.65rem'; @endphp
<div class="d-flex gap-1 align-items-center flex-shrink-0">
    @if($workshop->moysklad_sync_status && !$workshop->isSynced())
        <span class="badge {{ $workshop->syncStatusBadgeClass() }}" style="font-size:{{ $font }}">{{ $workshop->syncStatusLabel() }}</span>
    @endif
    @if($workshop->status === 'active')
        <span class="badge bg-success" style="font-size:{{ $font }}">Активна</span>
    @elseif($workshop->status === 'completed')
        <span class="badge bg-warning text-dark" style="font-size:{{ $font }}">Закрыта</span>
    @else
        <span class="badge bg-danger" style="font-size:{{ $font }}">Ошибка</span>
    @endif
</div>
