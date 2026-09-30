<?php

namespace App\Http\Requests\Order;

use App\Services\InternalOrderService;

class UpdateInternalOrderRequest extends InternalOrderRequest
{
    /** Заявка покупателя — 404: её состав приходит из МойСклад. Состав правит заказчик. */
    public function authorize(): bool
    {
        $order = $this->routeOrder();
        abort_unless($order->isInternal(), 404);

        return app(InternalOrderService::class)->canManage($this->user(), $order);
    }

    public function rules(): array
    {
        return [
            'executor_department_id' => [
                'required', 'integer', 'exists:departments,id',
                'not_in:' . (int) $this->routeOrder()->customer_department_id,
            ],
        ] + $this->itemRules();
    }

    public function messages(): array
    {
        return parent::messages() + [
            'executor_department_id.not_in' => 'Исполнитель должен отличаться от заказчика',
        ];
    }
}
