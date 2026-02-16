<!DOCTYPE html>
<html>
<head>
    <title>Заказы из МойСклад</title>
</head>
<body>
<h1>Список заказов</h1>

<ul>
    @foreach($list as $order)
        <li>
            Заказ: {{ $order->name }}<br>
{{--            Сумма: {{ $order->sum / 100 }} руб.--}}
        </li>
    @endforeach
</ul>
</body>
</html>
