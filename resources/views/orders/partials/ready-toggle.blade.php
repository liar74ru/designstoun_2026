{{--
    Кнопка ручной отметки «позиция готова». Параметры: $order, $product.
    Стили и скрипт — orders.partials.ready-toggle-assets.
--}}
<button type="button" class="ready-toggle flex-shrink-0"
        data-url="{{ route('orders.position.ready', [$order->uuid, $product->id]) }}"
        title="Отметить готовой / снять отметку">
    <i class="bi bi-check-lg"></i>
</button>
