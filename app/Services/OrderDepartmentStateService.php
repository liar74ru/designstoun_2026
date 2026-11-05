<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Order;
use App\Models\OrderState;
use App\Models\User;
use App\Services\Moysklad\CustomerOrderSyncService;
use Illuminate\Support\Collection;

/**
 * Финальный статус заявки по отделам.
 *
 * Статус у заявки один (поле в МойСклад). Если у неё несколько отделов, финальный статус
 * (OrderState::is_final, например «Собран») каждый отдел ставит только для себя — отметка
 * в pivot order_department. Общим он становится, когда отметились все отделы заявки:
 * уходит статус последнего. Иначе первый закончивший отдел закрыл бы окно производства,
 * и изготовленное остальными ушло бы в раздаче другим заявкам.
 *
 * Администратор меняет общий статус сразу. Мастер нескольких отделов заявки выбирает, за какие
 * из них ставит статус; выбрал все отделы заявки — она закрывается целиком.
 */
class OrderDepartmentStateService
{
    /** @var Collection<string, OrderState>|null */
    private ?Collection $states = null;

    public function __construct(
        private CustomerOrderSyncService $sync,
        private OrderChangeService $changes,
        private OrderProductionService $production,
    ) {}

    /**
     * Сменить статус заявки от имени пользователя.
     *
     * Мастер нескольких отделов заявки ставит статус за выбранные из них ($departmentIds),
     * без выбора — за все свои. Чужие отделы в выборе отбрасываются.
     *
     * @param  array<int, int|string>|null  $departmentIds
     * @return array{success: bool, code: string, message: string}  code 'unchanged' — менять нечего
     */
    public function apply(Order $order, OrderState $state, ?User $user, ?array $departmentIds = null): array
    {
        $order->loadMissing('departments');
        $own = $this->ownDepartmentIds($order, $user);

        if (! $this->isPerDepartment($order, $own)) {
            if ($order->state_moysklad_id === $state->id) {
                return $this->unchanged($state);
            }

            return $this->changeGlobal($order, $state);
        }

        $target = $departmentIds === null
            ? $own
            : array_values(array_intersect($own, array_map('intval', $departmentIds)));

        if ($target === []) {
            return ['success' => false, 'code' => 'no_departments', 'message' => 'Выберите отдел.'];
        }

        $marks = $this->marks($order);

        if ($state->is_final) {
            $targetStates = array_unique(array_map(fn ($id) => $marks[$id] ?? null, $target));
            if ($targetStates === [$state->id]) {
                return $this->unchanged($state);
            }

            $pending = $order->departments
                ->reject(fn (Department $d) => in_array($d->id, $target, true) || isset($marks[$d->id]));

            // Последний отдел: заявка закрывается целиком, отметки сбросит applyState.
            if ($pending->isEmpty()) {
                return $this->changeGlobal($order, $state);
            }

            $order->departments()->updateExistingPivot($target, [
                'final_state_id' => $state->id,
                'finalized_at'   => now(),
            ]);

            $names = $order->departments->whereIn('id', $target)->pluck('name')->join(', ');

            return [
                'success' => true,
                'code'    => '',
                'message' => "{$names}: «{$state->name}». Ждём: " . $pending->pluck('name')->join(', ') . '.',
            ];
        }

        // Нефинальный статус: отдел вернулся в работу — снимаем его отметку.
        $hadMarks = array_intersect($target, array_keys($marks)) !== [];

        if ($order->state_moysklad_id === $state->id) {
            if (! $hadMarks) {
                return $this->unchanged($state);
            }

            $this->clearMarks($order, $target);

            return ['success' => true, 'code' => '', 'message' => "Заявка снова в статусе «{$state->name}»."];
        }

        $result = $this->changeGlobal($order, $state);

        if ($result['success'] && $hadMarks) {
            $this->clearMarks($order, $target);
        }

        return $result;
    }

    /**
     * Закрыть заявку, если все её отделы уже отметились финальным статусом, — после снятия
     * отдела, который один ещё работал. Общим становится статус последней отметки.
     *
     * @return array{success: bool, code: string, message: string}|null  null — закрывать нечего
     */
    public function finalizeIfComplete(Order $order): ?array
    {
        $order->load('departments');

        if ($order->departments->isEmpty() || $this->stateOf($order->state_moysklad_id)?->is_final) {
            return null;
        }

        if ($order->departments->contains(fn (Department $d) => $d->pivot->final_state_id === null)) {
            return null;
        }

        $last  = $order->departments->sortBy(fn (Department $d) => (string) $d->pivot->finalized_at)->last();
        $state = $this->stateOf($last->pivot->final_state_id);

        return $state ? $this->changeGlobal($order, $state) : null;
    }

    /**
     * Что показать смотрящему о финальных статусах отделов.
     *
     * state    — статус, который видит смотрящий: финальный статус его отделов, если все они
     *            на заявке отметились (иначе null — показывается общий);
     * waiting  — отделы, которые ещё работают (только когда state задан);
     * finished — отметившиеся отделы: [['name' => ..., 'state' => OrderState], ...];
     * choices  — свои отделы на заявке [id => имя], если их больше одного: в меню статуса
     *            мастер выбирает, за какие из них ставит статус.
     *
     * @return array{state: ?OrderState, waiting: array<int, string>, finished: array<int, array{name: string, state: OrderState}>, choices: array<int, string>}|null
     *         null — нет ни отметок, ни выбора отделов
     */
    public function viewFor(Order $order, ?User $user): ?array
    {
        $own = $this->ownDepartmentIds($order, $user) ?? [];

        $choices = count($own) > 1 && $order->departments->count() > 1
            ? $order->departments->whereIn('id', $own)->pluck('name', 'id')->all()
            : [];

        $marks = $this->stateOf($order->state_moysklad_id)?->is_final ? [] : $this->marks($order);

        if ($marks === [] && $choices === []) {
            return null;
        }

        $finished = [];
        foreach ($order->departments as $dept) {
            $state = isset($marks[$dept->id]) ? $this->stateOf($marks[$dept->id]) : null;
            if ($state) {
                $finished[$dept->id] = ['name' => $dept->name, 'state' => $state];
            }
        }

        $state = null;

        if ($own !== [] && $finished !== [] && array_diff($own, array_keys($finished)) === []) {
            $latest = $order->departments->whereIn('id', $own)
                ->sortBy(fn (Department $d) => (string) $d->pivot->finalized_at)->last();
            $state = $finished[$latest->id]['state'];
        }

        return [
            'state'    => $state,
            'waiting'  => $state
                ? $order->departments->reject(fn (Department $d) => isset($finished[$d->id]))->pluck('name')->values()->all()
                : [],
            'finished' => array_values($finished),
            'choices'  => $choices,
        ];
    }

    /**
     * Общая смена статуса — запись в МойСклад, затем окно производства и «Изменено».
     *
     * @return array{success: bool, code: string, message: string}
     */
    private function changeGlobal(Order $order, OrderState $state): array
    {
        $previousStateId = $order->state_moysklad_id;
        $result = $this->sync->updateState($order, $state);

        // Окно производства открывается/закрывается только после успешной записи
        // в МойСклад — иначе снимок остатка лёг бы под статус, которого там нет.
        if ($result['success']) {
            $this->changes->rememberStateBeforeChange($order, $previousStateId, $state->id);
            $this->production->syncPeriod($order, $state->id);
        }

        return $result;
    }

    /**
     * Режим «по отделам»: не админ, у заявки больше одного отдела, и среди них есть отделы
     * пользователя. Мастер всех отделов заявки тоже в этом режиме: выбрал все — заявка
     * закроется целиком (в apply() не останется ждущих отделов), выбрал часть — нет.
     *
     * @param  array<int, int>|null  $own
     */
    private function isPerDepartment(Order $order, ?array $own): bool
    {
        return $own !== null
            && $own !== []
            && $order->departments->count() > 1;
    }

    /**
     * Отделы заявки, от имени которых действует пользователь. null — админ (действует за всех).
     *
     * @return array<int, int>|null
     */
    private function ownDepartmentIds(Order $order, ?User $user): ?array
    {
        $accessible = $user?->accessibleDepartmentIds();

        if ($accessible === null) {
            return null;
        }

        return $order->departments->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->intersect(array_map('intval', $accessible))
            ->values()->all();
    }

    /**
     * Отметки отделов: [department_id => final_state_id].
     *
     * @return array<int, string>
     */
    private function marks(Order $order): array
    {
        return $order->departments
            ->filter(fn (Department $d) => $d->pivot?->final_state_id !== null)
            ->mapWithKeys(fn (Department $d) => [(int) $d->id => $d->pivot->final_state_id])
            ->all();
    }

    /** @param  array<int, int>  $departmentIds */
    private function clearMarks(Order $order, array $departmentIds): void
    {
        $order->departments()->updateExistingPivot($departmentIds, [
            'final_state_id' => null,
            'finalized_at'   => null,
        ]);
    }

    private function stateOf(?string $id): ?OrderState
    {
        if ($id === null) {
            return null;
        }

        $this->states ??= OrderState::all()->keyBy('id');

        return $this->states->get($id);
    }

    /** @return array{success: bool, code: string, message: string} */
    private function unchanged(OrderState $state): array
    {
        return ['success' => true, 'code' => 'unchanged', 'message' => 'Заявка уже в статусе «' . $state->name . '».'];
    }
}
