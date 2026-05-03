<?php

namespace App\Models\Concerns;

trait HasMoyskladSync
{
    const SYNC_STATUS_SYNCED     = 'synced';
    const SYNC_STATUS_NOT_SYNCED = 'not_synced';

    public function hasMoySkladProcessing(): bool
    {
        return !empty($this->moysklad_processing_id);
    }

    public function hasSyncError(): bool
    {
        return !empty($this->moysklad_sync_error);
    }

    public function isSynced(): bool
    {
        return $this->moysklad_sync_status === self::SYNC_STATUS_SYNCED;
    }

    public function syncStatusLabel(): string
    {
        return $this->isSynced() ? 'Синхр' : 'Не синхр';
    }

    public function syncStatusBadgeClass(): string
    {
        return $this->isSynced() ? 'bg-success' : 'bg-danger';
    }

    public function markSynced(string $processingId, ?string $processingName = null): void
    {
        $this->update([
            'moysklad_processing_id'   => $processingId,
            'moysklad_processing_name' => $processingName ?? $this->moysklad_processing_name,
            'moysklad_sync_status'     => self::SYNC_STATUS_SYNCED,
            'moysklad_sync_error'      => null,
            'synced_at'                => now(),
        ]);
    }

    public function markSyncError(string $error): void
    {
        $this->update([
            'moysklad_sync_status' => self::SYNC_STATUS_NOT_SYNCED,
            'moysklad_sync_error'  => $error,
            'synced_at'            => now(),
        ]);
    }
}
