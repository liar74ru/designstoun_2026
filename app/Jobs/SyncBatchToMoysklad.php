<?php

namespace App\Jobs;

use App\Models\RawMaterialBatch;
use App\Services\Moysklad\RawMaterialBatchSyncService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Attributes\WithoutRelations;

class SyncBatchToMoysklad extends SyncDocumentJob
{
    /**
     * @param array $alsoRefresh moysklad_id прежнего товара, если его сменили: его остаток в МойСклад
     *                           тоже меняется, а из записи его уже не получить.
     */
    public function __construct(
        #[WithoutRelations]
        public RawMaterialBatch $batch,
        public array $alsoRefresh = [],
    ) {}

    public function handle(RawMaterialBatchSyncService $syncService): void
    {
        $this->settle($syncService->pushBatch($this->batch, $this->alsoRefresh));
    }

    protected function document(): Model
    {
        return $this->batch;
    }
}
