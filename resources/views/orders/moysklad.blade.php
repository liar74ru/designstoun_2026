<!DOCTYPE html>
<html>
<head>
    <title>Заказы из МойСклад</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; }
        h1 { margin-top: 0; color: #333; }
        .nav { margin-bottom: 20px; }
        .nav a { padding: 10px 15px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; margin-right: 10px; }
        .nav a:hover { background: #0056b3; }
        .info-bar { background: #e3f2fd; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8f9fa; padding: 12px; text-align: left; border-bottom: 2px solid #dee2e6; }
        td { padding: 12px; border-bottom: 1px solid #dee2e6; }
        tr:hover { background: #f8f9fa; }
        .status { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
        .status-Новый { background: #e3f2fd; color: #1976d2; }
        .status-В { background: #fff3e0; color: #f57c00; }
        .status-Отгружен { background: #e8f5e8; color: #388e3c; }
        .status-Отменен { background: #ffebee; color: #d32f2f; }
        .badge { background: #28a745; color: white; padding: 2px 8px; border-radius: 12px; font-size: 12px; }
    </style>
</head>
<body>
<div class="container">
    <h1>📦 Заказы из МойСклад (последние 50)</h1>

    <div class="nav">
        <a href="{{ route('orders.index') }}">📋 К локальным заказам</a>
    </div>

    <div class="info-bar">
        <strong>ℹ️ Информация:</strong>
        Получено заказов: {{ $orders->count() }}
        @isset($total)
            | Всего в МойСклад: {{ $total }}
        @endisset
        @isset($saved_count)
            | Сохранено в БД: {{ $saved_count }}
        @endisset
    </div>

    @if($orders->count() > 0)
        <table>
            <thead>
            <tr>
                <th>Номер заказа</th>
                <th>Дата</th>
                <th>Контрагент</th>
                <th>Сумма</th>
                <th>Оплачено</th>
                <th>Статус</th>
            </tr>
            </thead>
            <tbody>
            @foreach($orders as $order)
                <tr>
                    <td><strong>{{ $order->name }}</strong></td>
                    <td>{{ isset($order->moment) ? date('d.m.Y H:i', strtotime($order->moment)) : 'Н/Д' }}</td>
                    <td>{{ $order->agent_name ?? 'Не указан' }}</td>
                    <td>{{ number_format($order->sum, 2, ',', ' ') }} ₽</td>
                    <td>
                        {{ number_format($order->payed_sum, 2, ',', ' ') }} ₽
                        @if($order->payed_sum >= $order->sum)
                            <span class="badge">оплачен</span>
                        @endif
                    </td>
                    <td>
                            <span class="status status-{{ $order->state_name ?? 'Новый' }}">
                                {{ $order->state_name ?? 'Новый' }}
                            </span>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @else
        <p>Заказы не найдены в МойСклад</p>
    @endif
</div>
</body>
</html>
