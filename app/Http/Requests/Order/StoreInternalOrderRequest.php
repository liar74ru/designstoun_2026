<?php

namespace App\Http\Requests\Order;

use App\Support\DepartmentAccess;
use Illuminate\Validation\Rule;

class StoreInternalOrderRequest extends InternalOrderRequest
{
    /** Заказать полуфабрикат можно под заявку, к которой есть доступ; под внутренний — нет. */
    public function authorize(): bool
    {
        abort_if($this->routeOrder()->isInternal(), 404);

        return true;
    }

    public function rules(): array
    {
        $parent  = $this->routeOrder();
        $user    = $this->user();
        $parentDepartmentIds = $parent->departments->pluck('id')->all();

        return [
            // Заказчик — отдел заявки, к которому пользователь может отнести запись.
            'customer_department_id' => array_filter([
                'required', 'integer', 'exists:departments,id',
                $parentDepartmentIds === [] ? null : Rule::in($parentDepartmentIds),
                function ($attribute, $value, $fail) use ($user) {
                    if (! DepartmentAccess::canAssign($user, $value)) {
                        $fail('Заказывать можно только от своего отдела.');
                    }
                },
            ]),
            // Исполнитель — любой другой отдел: заказ чужому отделу здесь норма.
            'executor_department_id' => 'required|integer|exists:departments,id|different:customer_department_id',
            'state_id'               => ['required', 'string', Rule::exists('order_states', 'id')->where('is_enabled', true)],
        ] + $this->itemRules();
    }

    public function messages(): array
    {
        return parent::messages() + [
            'customer_department_id.in' => 'Заказчиком может быть только отдел заявки',
            'state_id.exists'           => 'Этот статус не используется в программе',
        ];
    }
}
