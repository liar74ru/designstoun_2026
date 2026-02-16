<!DOCTYPE html>
<html>
<head>
    <title>Заказы из БД</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; }
        h1 { margin-top: 0; color: #333; }
        .nav { margin-bottom: 20px; }
        .nav a { padding: 10px 15px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; margin-right: 10px; }
        .nav a:hover { background: #0056b3; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8f9fa; padding: 12px; text-align: left; border-bottom: 2px solid #dee2e6; }
        td { padding: 12px; border-bottom: 1px solid #dee2e6; }
        tr:hover { background: #f8f9fa; }
        .status { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
        .status-new { background: #e3f2fd; color: #1976d2; }
        .status-processing { background: #fff3e0; color: #f57c00; }
        .status-completed { background: #e8f5e8; color: #388e3c; }
        .status-canceled { background: #ffebee; color: #d32f2f; }
        .error { background: #ffebee; color: #c62828; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .success { background: #e8f5e9; color: #2e7d32; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .pagination { margin-top: 20px; }
    </style>
</head>
<body>
<div class="nav">
    <a href="{{ route('products.create') }}">➕ Создать товар</a>
    <a href="{{ route('moysklad.sync.products') }}" onclick="return confirm('Синхронизировать товары с МойСклад?')">🔄 Синхронизировать с МойСклад</a>
    <a href="{{ route('products.index') }}?moysklad">📦 Просмотр из МойСклад</a>
    <a href="{{ route('orders.index') }}">📋 К заказам</a>
</div>
<div class="container">
    <h1>📦 Заказы из локальной БД</h1>

    <div class="nav">
        <a href="{{ route('orders.index') }}?moysklad">🔄 Получить из МойСклад</a>
        <a href="{{ route('products.index') }}">📱 К товарам</a>
    </div>

    @if(session('error'))
        <div class="error">{{ session('error') }}</div>
    @endif

    @if(session('success'))
        <div class="success">{{ session('success') }}</div>
    @endif

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
                <th>Действия</th>
            </tr>
            </thead>
            <tbody>
            @foreach($orders as $order)
                <tr>
                    <td><strong>{{ $order->name }}</strong></td>
                    <td>{{ $order->moment ? $order->moment->format('d.m.Y H:i') : 'Н/Д' }}</td>
                    <td>{{ $order->agent_name ?? 'Не указан' }}</td>
                    <td>{{ number_format($order->sum, 2, ',', ' ') }} ₽</td>
                    <td>{{ number_format($order->payed_sum, 2, ',', ' ') }} ₽</td>
                    <td>
                            <span class="status status-{{ $order->state ?? 'new' }}">
                                {{ $order->status_text }}
                            </span>
                    </td>
                    <td>
                        <a href="{{ route('orders.show', $order->moysklad_id) }}">👁️ Просмотр</a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <div class="pagination">
            {{ $orders->links() }}
        </div>
    @else
        <p>Заказы не найдены. <a href="{{ route('orders.index') }}?moysklad">Загрузить из МойСклад</a></p>
    @endif
</div>
</body>
</html>
