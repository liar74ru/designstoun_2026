<?php

namespace App\Services;

use App\Models\Department;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

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
     * Данные формы создания/правки: отделы и должности, которые пользователь вправе назначить.
     * foreignDepartments — отделы работника вне зоны пользователя: в форме только для чтения,
     * при сохранении остаются как есть.
     */
    public function formOptions(User $by, ?Worker $worker = null): array
    {
        $accessible = $by->accessibleDepartmentIds();

        $departments = Department::where('is_active', true)
            ->when($accessible !== null, fn ($q) => $q->whereIn('id', $accessible ?: [-1]))
            ->orderBy('name')
            ->get();

        $foreignDepartments = collect();
        if ($worker && $accessible !== null) {
            $foreignDepartments = Department::whereIn('id', array_diff($worker->departmentIds(), $accessible))
                ->orderBy('name')
                ->get();
        }

        return [
            'departments'        => $departments,
            'foreignDepartments' => $foreignDepartments,
            'positions'          => $this->assignablePositions($by, $worker),
            'assignmentLocked'   => !$this->canChangeAssignment($by, $worker),
        ];
    }

    /**
     * Должности, которые пользователь может назначить. Мастерские (Worker::MASTER_POSITIONS)
     * назначает только админ; текущую должность работника не-админ может оставить.
     *
     * @return string[]
     */
    public function assignablePositions(User $by, ?Worker $worker = null): array
    {
        if ($by->isAdmin()) {
            return Worker::POSITIONS;
        }

        return array_values(array_filter(
            Worker::POSITIONS,
            fn ($pos) => !in_array($pos, Worker::MASTER_POSITIONS, true) || $pos === $worker?->position
        ));
    }

    /** Отделы и должность своей карточки не-админ не меняет. */
    public function canChangeAssignment(User $by, ?Worker $worker = null): bool
    {
        return $by->isAdmin() || $worker === null || $by->worker_id !== $worker->id;
    }

    public function create(array $data, User $by): Worker
    {
        return DB::transaction(function () use ($data, $by) {
            $worker = Worker::create(Arr::except($data, ['department_ids']));

            [$ids, $primaryId] = $this->resolveDepartments(null, $data, $by);
            $this->syncDepartments($worker, $ids, $primaryId);

            return $worker;
        });
    }

    public function update(Worker $worker, array $data, User $by): void
    {
        DB::transaction(function () use ($worker, $data, $by) {
            if (!$this->canChangeAssignment($by, $worker)) {
                $worker->update(Arr::only($data, ['name', 'email', 'phone']));
            } else {
                [$ids, $primaryId] = $this->resolveDepartments($worker, $data, $by);
                $worker->update(Arr::except($data, ['department_ids', 'department_id']));
                $this->syncDepartments($worker, $ids, $primaryId);
            }

            $this->syncPhoneToUser($worker, $data['phone'] ?? null);
        });
    }

    /**
     * Отделы из формы + отделы работника вне зоны пользователя: их не-админ не видит
     * в форме и снять не может. Основной отдел вне зоны тоже остаётся прежним.
     *
     * @return array{0: int[], 1: int|null}
     */
    private function resolveDepartments(?Worker $worker, array $data, User $by): array
    {
        $ids        = array_map('intval', $data['department_ids'] ?? []);
        $primaryId  = isset($data['department_id']) ? (int) $data['department_id'] : null;
        $accessible = $by->accessibleDepartmentIds();

        if ($accessible === null || $worker === null) {
            return [$ids, $primaryId];
        }

        $worker->load('departments');
        $ids = array_merge($ids, array_diff($worker->departmentIds(), $accessible));

        if ($worker->department_id && !in_array((int) $worker->department_id, $accessible, true)) {
            $primaryId = (int) $worker->department_id;
        }

        return [$ids, $primaryId];
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
