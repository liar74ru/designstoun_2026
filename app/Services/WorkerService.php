<?php

namespace App\Services;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Builder;

// рефакторинг v2 от 26.04.2026 — controller → service
class WorkerService
{
    public function buildIndexQuery(array $filters, $authUser): Builder
    {
        $query = Worker::with('department');

        if ($authUser->isMaster() && $authUser->worker?->department_id) {
            $query->where('department_id', $authUser->worker->department_id);
        }

        if (!empty($filters['position'])) {
            $query->where('position', $filters['position']);
        }

        if (!empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (isset($filters['has_account'])) {
            if ($filters['has_account'] == '1') {
                $query->whereHas('user');
            } else {
                $query->whereDoesntHave('user');
            }
        }

        return $query->orderByRaw($this->positionSortSql())->orderBy('id');
    }

    public function syncPhoneToUser(Worker $worker, ?string $newPhone): void
    {
        if ($worker->user && $worker->user->phone !== $newPhone) {
            $worker->user->update(['phone' => $newPhone]);
        }
    }

    public function createUser(Worker $worker, string $password): array
    {
        if (!$worker->phone) {
            return ['success' => false, 'message' => 'У работника не указан телефон. Сначала добавьте телефон.'];
        }

        if (User::where('phone', $worker->phone)->exists()) {
            return ['success' => false, 'message' => 'Этот телефон уже используется другим пользователем'];
        }

        User::create([
            'name'      => $worker->name,
            'phone'     => $worker->phone,
            'password'  => bcrypt($password),
            'worker_id' => $worker->id,
            'is_admin'  => false,
        ]);

        return ['success' => true];
    }

    public function updateUser(Worker $worker, string $password, bool $setAdmin, bool $callerIsAdmin): void
    {
        $data = [
            'password' => bcrypt($password),
            'phone'    => $worker->phone,
        ];

        if ($callerIsAdmin) {
            $data['is_admin'] = $setAdmin;
        }

        $worker->user->update($data);
    }

    private function positionSortSql(): string
    {
        return "CASE position
            WHEN 'Администратор'    THEN 1
            WHEN 'Мастер'           THEN 2
            WHEN 'Помощник мастера' THEN 3
            WHEN 'Работник'         THEN 4
            WHEN 'Разнорабочий'     THEN 5
            ELSE 6
          END";
    }
}
