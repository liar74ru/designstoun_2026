<?php

namespace App\Rules;

use App\Models\RawMaterialBatch;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Партия, на которую оформляется приёмка, должна быть «своей» (политика modify партии):
 * создание приёмки закрывает прежнюю приёмку партии, делит её и переносит в отдел
 * приёмки — всё это изменения партии. Прежняя партия приёмки ($keepBatchId) при правке
 * проходит без проверки: приёмку своего отдела можно править, не меняя партию.
 */
class ModifiableBatch implements ValidationRule
{
    public function __construct(
        private readonly User $user,
        private readonly ?int $keepBatchId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->keepBatchId !== null && (int) $value === $this->keepBatchId) {
            return;
        }

        $batch = RawMaterialBatch::find($value);

        if ($batch && !$this->user->can('modify', $batch)) {
            $fail('Партия другого отдела — приёмку на неё оформляет её отдел.');
        }
    }
}
