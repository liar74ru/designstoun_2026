<?php

namespace App\Http\Requests\Order;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Общее у создания и правки внутреннего заказа: строки состава и срок.
 * Доступ проверяется в authorize() — до валидации, как у остальных изменяющих форм.
 */
abstract class InternalOrderRequest extends FormRequest
{
    private ?Order $routeOrder = null;

    /** Заказ из маршрута: для создания — заявка-основание, для правки — сам внутренний заказ. */
    public function routeOrder(): Order
    {
        return $this->routeOrder ??= app(OrderService::class)
            ->findForUser($this, (string) $this->route('moyskladId'), ['items']);
    }

    protected function itemRules(): array
    {
        return [
            'delivery_planned_at' => 'nullable|date_format:Y-m-d',
            'items'               => 'required|array|min:1',
            'items.*.product_id'  => 'required|integer|exists:products,id',
            'items.*.quantity'    => 'required|numeric|min:0.001',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required'                => 'Добавьте хотя бы одну позицию',
            'items.*.product_id.required'   => 'Выберите товар',
            'items.*.quantity.min'          => 'Количество должно быть больше нуля',
            'executor_department_id.different' => 'Исполнитель должен отличаться от заказчика',
        ];
    }
}
