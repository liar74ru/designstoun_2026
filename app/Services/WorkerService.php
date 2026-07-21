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
        $query = Worker::with('department', 'departments');

        // Статус: active (по умолчанию) / archived / all
        $status = $filters['status'] ?? 'active';
        if ($status === 'archived') {
            $query->archived();
        } elseif ($status !== 'all') {
            $query->active();
        }

        // Мастер видит работников всех своих отделов
        if ($authUser->isMaster() && $authUser->worker) {
            $masterDepartmentIds = $authUser->worker->departmentIds();
            $query->whereHas('departments', fn($q) => $q->whereIn('departments.id', $masterDepartmentIds ?: [-1]));
        }

        if (!empty($filters['position'])) {
            $query->where('position', $filters['position']);
        }

        if (!empty($filters['department_id'])) {
            $query->whereHas('departments', fn($q) => $q->where('departments.id', $filters['department_id']));
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

    /**
     * Синхронизировать отделы работника.
     * Инвариант: основной отдел всегда присутствует в pivot; если основной не задан,
     * им становится первый из выбранных.
     *
     * @param int[]|string[] $departmentIds
     */
    public function syncDepartments(Worker $worker, array $departmentIds, ?int $primaryId = null): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $departmentIds))));

        if ($primaryId && !in_array($primaryId, $ids, true)) {
            $ids[] = $primaryId;
        }
        if (!$primaryId) {
            $primaryId = $ids[0] ?? null;
        }

        $worker->departments()->sync($ids);
        $worker->update(['department_id' => $primaryId]);
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
