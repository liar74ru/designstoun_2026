<!DOCTYPE html>
<html>
<head>
    <title>Товары из МойСклад</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; }
        h1 { margin-top: 0; color: #333; }
        .nav { margin-bottom: 20px; display: flex; gap: 10px; }
        .nav a { padding: 10px 15px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; }
        .nav a:hover { background: #0056b3; }
        .nav a.orders { background: #28a745; }
        .nav a.orders:hover { background: #218838; }
        .info-bar { background: #e3f2fd; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
        .product-card { border: 1px solid #ddd; border-radius: 8px; padding: 15px; background: white; transition: transform 0.2s; }
        .product-card:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .product-badge { background: #007bff; color: white; padding: 3px 8px; border-radius: 4px; font-size: 12px; display: inline-block; margin-bottom: 10px; }
        .product-name { font-size: 16px; font-weight: bold; margin: 10px 0; color: #333; }
        .product-sku { color: #666; font-size: 12px; margin: 5px 0; }
        .product-price { font-size: 18px; font-weight: bold; color: #2c3e50; margin: 10px 0; }
        .product-stock { font-size: 14px; margin: 10px 0; }
        .in-stock { color: #28a745; }
        .out-of-stock { color: #dc3545; }
        .badge { background: #28a745; color: white; padding: 2px 6px; border-radius: 4px; font-size: 12px; margin-left: 10px; }
        .updated { color: #666; font-size: 11px; margin-top: 10px; }
    </style>
</head>
<body>
<div class="container">
    <h1>📦 Товары из МойСклад (последние 50)</h1>

    <div class="nav">
        <a href="{{ route('products.index') }}">📋 К локальным товарам</a>
        <a href="{{ route('orders.index') }}" class="orders">📋 К заказам</a>
    </div>

    <div class="info-bar">
        <strong>ℹ️ Информация:</strong>
        Получено товаров: {{ $products->count() }}
        @isset($total)
            | Всего в МойСклад: {{ $total }}
        @endisset
        @isset($saved_count)
            | Сохранено в БД: {{ $saved_count }}
        @endisset
    </div>

    @if($products->count() > 0)
        <div class="product-grid">
            @foreach($products as $product)
                <div class="product-card">
                    <span class="product-badge">МойСклад</span>

                    <div class="product-name">{{ $product->name }}</div>
                    <div class="product-sku">Артикул: {{ $product->sku }}</div>

                    <div class="product-price">
                        {{ number_format($product->price, 2, ',', ' ') }} ₽
                        @if(property_exists($product, 'old_price') && $product->old_price)
                            <span style="text-decoration: line-through; color: #999; font-size: 14px;">
        {{ number_format($product->old_price, 2, ',', ' ') }} ₽
    </span>
                        @endif
                    </div>

                    <div class="product-stock {{ $product->quantity > 0 ? 'in-stock' : 'out-of-stock' }}">
                        @if($product->quantity > 0)
                            ✅ В наличии: {{ $product->quantity }} шт.
                        @else
                            ❌ Нет в наличии
                        @endif
                    </div>

                    @if($product->path_name)
                        <div style="color: #666; font-size: 12px; margin: 5px 0;">
                            📁 {{ $product->path_name }}
                        </div>
                    @endif

                    @if($product->description)
                        <p style="color: #666; font-size: 13px;">{{ Str::limit($product->description, 60) }}</p>
                    @endif

                    @if($product->updated)
                        <div class="updated">
                            Обновлен: {{ date('d.m.Y H:i', strtotime($product->updated)) }}
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @else
        <p>Товары не найдены в МойСклад</p>
    @endif
</div>
</body>
</html>
