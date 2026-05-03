@props([
    'model',
    'syncRoute',
    'wrapper'    => 'card',
    'showButton' => true,
    'emptyText'  => 'Техоперация не создана',
    'createText' => 'Создать техоперацию',
    'syncText'   => 'Синхронизировать с МойСклад',
])

@php
    $isCard = $wrapper === 'card';
@endphp

<div class="{{ $isCard ? 'card shadow-sm mb-3' : 'info-block' }}">
    <div class="{{ $isCard ? 'card-header bg-white py-2' : 'info-block-header' }} d-flex justify-content-between align-items-center">
        <span class="small fw-semibold text-muted">
            <i class="bi bi-cloud me-1"></i>МойСклад
        </span>
        @if($model->moysklad_sync_status)
            <span class="badge {{ $model->syncStatusBadgeClass() }} small">
                {{ $model->syncStatusLabel() }}
            </span>
        @endif
    </div>
    <div class="{{ $isCard ? 'card-body p-2' : 'info-block-body' }}">
        @if($model->hasSyncError())
            <div class="small text-warning-emphasis">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <strong>Ошибка:</strong> {{ $model->moysklad_sync_error }}
            </div>
        @elseif($model->isSynced())
            <div class="small text-success">
                <i class="bi bi-check-circle me-1"></i>Синхронизировано
                @if($model->moysklad_processing_name)
                    · <span class="text-muted">{{ $model->moysklad_processing_name }}</span>
                @endif
            </div>
            @if(auth()->user()->is_admin && $model->moysklad_processing_id)
                <div class="text-muted mt-1" style="font-size:.72rem;word-break:break-all">
                    <i class="bi bi-fingerprint me-1"></i>
                    <code style="font-size:.7rem">{{ $model->moysklad_processing_id }}</code>
                </div>
            @endif
        @else
            <div class="small text-muted">
                <i class="bi bi-cloud-slash me-1"></i>{{ $emptyText }}
            </div>
        @endif

        @if($model->synced_at)
            <div class="text-muted mt-2" style="font-size:.72rem">
                <i class="bi bi-clock-history me-1"></i>
                Последняя синхр.: {{ $model->synced_at->format('d.m.Y H:i') }}
            </div>
        @endif

        @if($showButton)
            <form method="POST" action="{{ $syncRoute }}" class="mt-2">
                @csrf
                <button type="submit"
                        class="btn btn-sm w-100 {{ $model->hasSyncError() ? 'btn-warning' : ($model->hasMoySkladProcessing() ? 'btn-outline-secondary' : 'btn-outline-primary') }}">
                    <i class="bi bi-arrow-repeat me-1"></i>
                    {{ $model->hasMoySkladProcessing() ? $syncText : $createText }}
                </button>
            </form>
        @endif
    </div>
</div>
