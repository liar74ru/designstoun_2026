<?php

namespace App\Jobs;

use App\Models\Workshop;
use App\Services\Moysklad\WorkshopSyncService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Attributes\WithoutRelations;

class SyncWorkshopToMoysklad extends SyncDocumentJob
{
    public function __construct(
        #[WithoutRelations]
        public Workshop $workshop,
        public ?string $customName = null,
    ) {}

    public function handle(WorkshopSyncService $syncService): void
    {
        $this->settle($syncService->pushWorkshop($this->workshop, $this->customName));
    }

    protected function document(): Model
    {
        return $this->workshop;
    }
}
