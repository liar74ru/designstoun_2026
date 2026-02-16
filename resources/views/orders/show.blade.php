<!DOCTYPE html>
<html>
<head>
    <title>Заказ {{ $order->name }}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; }
        h1 { margin-top: 0; }
        .nav { margin-bottom: 20px; }
        .nav a { padding: 8px 12px; background: #6c757d; color: white; text-decoration: none; border-radius: 4px; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0; }
        .info-card { background: #f8f9fa; padding: 20px; border-radius: 8px; }
        .info-card h3 { margin-top: 0; }
        .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #dee2e6; }
        .label { font-weight: bold; color: #495057; }
        .value { color: #212529; }
        .status { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 14px; }
        .status-new { background: #e3f2fd; color: #1976d2; }
        .status-completed { background: #e8f5e8; color: #388e3c; }
        .refresh-btn { background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 10px; }
    </style>
</head>
<body>
<div class="container">
    <div class="nav">
        <a href="{{ route('orders.index') }}">← Назад к списку</a>
    </div>

    <h1>Заказ {{ $order->name }}</h1>

    <div class="info-grid">
        <div class="info-card">
            <h3>Основная информация</h3>
            <div class="info-row">
                <span class="label">Номер:</span>
                <span class="value">{{ $order->name }}</span>
            </div>
            <div class="info-row">
                <span class="label">Дата:</span>
                <span class="value">{{ $order->moment ? $order->moment->format('d.m.Y H:i') : 'Н/Д' }}</span>
            </div>
            <div class="info-row">
                <span class="label">Статус:</span>
                <span class="value">
                        <span class="status status-{{ $order->state ?? 'new' }}">
                            {{ $order->status_text }}
                        </span>
                    </span>
            </div>
            <div class="info-row">
                <span class="label">Описание:</span>
                <span class="value">{{ $order->description ?: 'Нет' }}</span>
            </div>
        </div>

        <div class="info-card">
            <h3>Финансовая информация</h3>
            <div class="info-row">
                <span class="label">Сумма заказа:</span>
                <span class="value">{{ number_format($order->sum, 2, ',', ' ') }} ₽</span>
            </div>
            <div class="info-row">
                <span class="label">Оплачено:</span>
                <span class="value">{{ number_format($order->payed_sum, 2, ',', ' ') }} ₽</span>
            </div>
            <div class="info-row">
                <span class="label">Отгружено:</span>
                <span class="value">{{ number_format($order->shipped_sum, 2, ',', ' ') }} ₽</span>
            </div>
            <div class="info-row">
                <span class="label">Задолженность:</span>
                <span class="value">{{ number_format($order->sum - $order->payed_sum, 2, ',', ' ') }} ₽</span>
            </div>
        </div>

        <div class="info-card">
            <h3>Контрагент</h3>
            <div class="info-row">
                <span class="label">Название:</span>
                <span class="value">{{ $order->agent_name ?: 'Не указан' }}</span>
            </div>
            <div class="info-row">
                <span class="label">ID в МойСклад:</span>
                <span class="value">{{ $order->agent_id ?: 'Н/Д' }}</span>
            </div>
        </div>

        <div class="info-card">
            <h3>Организация</h3>
            <div class="info-row">
                <span class="label">Название:</span>
                <span class="value">{{ $order->organization_name ?: 'Не указана' }}</span>
            </div>
            <div class="info-row">
                <span class="label">ID в МойСклад:</span>
                <span class="value">{{ $order->organization_id ?: 'Н/Д' }}</span>
            </div>
        </div>
    </div>

    <div style="margin-top: 20px;">
        <a href="{{ route('orders.refresh', $order->moysklad_id) }}" class="refresh-btn">🔄 Обновить из МойСклад</a>
    </div>
</div>
</body>
</html>
