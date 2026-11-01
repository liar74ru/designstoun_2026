<?php

namespace App\Jobs;

use App\Models\StoneReception;
use App\Services\Moysklad\StoneReceptionSyncService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Attributes\WithoutRelations;

class SyncReceptionToMoysklad extends SyncProcessingJob
{
    /**
     * @param array $alsoRefresh moysklad_id товаров «до правки»: убранные позиции и сырьё прежней
     *                           партии. Из записи их уже не получить, поэтому едут в задаче.
     */
    public function __construct(
        #[WithoutRelations]
        public StoneReception $reception,
        public ?string $customName = null,
        public array $alsoRefresh = [],
    ) {}

    public function handle(StoneReceptionSyncService $syncService): void
    {
        $this->settle($syncService->pushReception($this->reception, $this->customName, $this->alsoRefresh));
    }

    protected function document(): Model
    {
        return $this->reception;
    }
}
