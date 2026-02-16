<!DOCTYPE html>
<html>
<head>
    <title>Товар {{ $product->name }}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; }
        h1 { margin-top: 0; }
        .nav { margin-bottom: 20px; }
        .nav a { padding: 8px 12px; background: #6c757d; color: white; text-decoration: none; border-radius: 4px; }
        .info-card { background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; }
        .info-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #dee2e6; }
        .label { font-weight: bold; color: #495057; width: 30%; }
        .value { color: #212529; width: 70%; }
        .price-current { font-size: 24px; color: #28a745; font-weight: bold; }
        .price-old { text-decoration: line-through; color: #999; margin-left: 10px; }
        .in-stock { color: #28a745; font-weight: bold; }
        .out-of-stock { color: #dc3545; font-weight: bold; }
        .refresh-btn { background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 10px; }
        .attributes { margin-top: 20px; }
        .attribute-tag { background: #e9ecef; padding: 4px 8px; border-radius: 4px; margin: 0 5px 5px 0; display: inline-block; }
    </style>
</head>
<body>
<div class="container">
    <div class="nav">
        <a href="{{ route('products.index') }}">← Назад к списку</a>
    </div>

    <h1>{{ $product->name }}</h1>

    <div class="info-card">
        <div class="info-row">
            <span class="label">Артикул:</span>
            <span class="value">{{ $product->sku }}</span>
        </div>
        <div class="info-row">
            <span class="label">Цена:</span>
            <span class="value">
                    <span class="price-current">{{ number_format($product->price, 2, ',', ' ') }} ₽</span>
                    @if($product->old_price)
                    <span class="price-old">{{ number_format($product->old_price, 2, ',', ' ') }} ₽</span>
                    <span style="color: #dc3545;">(-{{ $product->discount_percent }}%)</span>
                @endif
                </span>
        </div>
        <div class="info-row">
            <span class="label">Наличие:</span>
            <span class="value">
                    @if($product->in_stock)
                    <span class="in-stock">✅ В наличии: {{ $product->quantity }} шт.</span>
                @else
                    <span class="out-of-stock">❌ Нет в наличии</span>
                @endif
                </span>
        </div>
        <div class="info-row">
            <span class="label">Описание:</span>
            <span class="value">{{ $product->description ?: 'Нет описания' }}</span>
        </div>
        <div class="info-row">
            <span class="label">ID в МойСклад:</span>
            <span class="value">{{ $product->moysklad_id }}</span>
        </div>
        <div class="info-row">
            <span class="label">Дата создания:</span>
            <span class="value">{{ $product->created_at->format('d.m.Y H:i') }}</span>
        </div>
        <div class="info-row">
            <span class="label">Последнее обновление:</span>
            <span class="value">{{ $product->updated_at->format('d.m.Y H:i') }}</span>
        </div>

        @if($product->attributes && count($product->attributes) > 0)
            <div class="attributes">
                <div class="label">Дополнительные атрибуты:</div>
                <div style="margin-top: 10px;">
                    @foreach($product->attributes as $key => $value)
                        @if($value)
                            <span class="attribute-tag"><strong>{{ $key }}:</strong> {{ $value }}</span>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <a href="{{ route('products.refresh', $product->moysklad_id) }}" class="refresh-btn">🔄 Обновить из МойСклад</a>
</div>
</body>
</html>
