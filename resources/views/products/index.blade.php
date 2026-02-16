<!DOCTYPE html>
<html>
<head>
    <title>Товары из БД</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; }
        h1 { margin-top: 0; color: #333; }
        .nav { margin-bottom: 20px; display: flex; gap: 10px; }
        .nav a { padding: 10px 15px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; }
        .nav a:hover { background: #0056b3; }
        .nav a.orders { background: #28a745; }
        .nav a.orders:hover { background: #218838; }
        .product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
        .product-card { border: 1px solid #ddd; border-radius: 8px; padding: 15px; background: white; transition: transform 0.2s; }
        .product-card:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .product-name { font-size: 16px; font-weight: bold; margin: 10px 0; color: #333; }
        .product-sku { color: #666; font-size: 12px; margin: 5px 0; }
        .product-price { font-size: 18px; font-weight: bold; color: #2c3e50; margin: 10px 0; }
        .old-price { text-decoration: line-through; color: #999; font-size: 14px; margin-left: 10px; }
        .discount-badge { background: #dc3545; color: white; padding: 2px 6px; border-radius: 4px; font-size: 12px; margin-left: 10px; }
        .product-stock { font-size: 14px; margin: 10px 0; }
        .in-stock { color: #28a745; }
        .out-of-stock { color: #dc3545; }
        .error { background: #ffebee; color: #c62828; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .success { background: #e8f5e9; color: #2e7d32; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .pagination { margin-top: 30px; }
        .view-link { display: inline-block; margin-top: 10px; color: #007bff; text-decoration: none; }
        .view-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="nav">
    <a href="{{ route('orders.create') }}">➕ Создать заказ</a>
    <a href="{{ route('moysklad.sync.orders') }}" onclick="return confirm('Синхронизировать заказы с МойСклад?')">🔄 Синхронизировать с МойСклад</a>
    <a href="{{ route('orders.index') }}?moysklad">📦 Просмотр из МойСклад</a>
    <a href="{{ route('products.index') }}">📱 К товарам</a>
</div>
<div class="container">
    <h1>📦 Товары из локальной БД</h1>

    <div class="nav">
        <a href="{{ route('products.index') }}?moysklad">🔄 Получить из МойСклад</a>
        <a href="{{ route('orders.index') }}" class="orders">📋 К заказам</a>
    </div>

    @if(session('error'))
        <div class="error">{{ session('error') }}</div>
    @endif

    @if(session('success'))
        <div class="success">{{ session('success') }}</div>
    @endif

    @if($products->count() > 0)
        <div class="product-grid">
            @foreach($products as $product)
                <div class="product-card">
                    <div class="product-name">{{ $product->name }}</div>
                    <div class="product-sku">Артикул: {{ $product->sku }}</div>

                    <div class="product-price">
                        {{ number_format($product->price, 2, ',', ' ') }} ₽
                        @if($product->old_price)
                            <span class="old-price">{{ number_format($product->old_price, 2, ',', ' ') }} ₽</span>
                            <span class="discount-badge">-{{ $product->discount_percent }}%</span>
                        @endif
                    </div>

                    <div class="product-stock {{ $product->in_stock ? 'in-stock' : 'out-of-stock' }}">
                        @if($product->in_stock)
                            ✅ В наличии: {{ $product->quantity }} шт.
                        @else
                            ❌ Нет в наличии
                        @endif
                    </div>

                    @if($product->description)
                        <p style="color: #666; font-size: 13px;">{{ Str::limit($product->description, 60) }}</p>
                    @endif

                    <a href="{{ route('products.show', $product->moysklad_id) }}" class="view-link">👁️ Подробнее</a>
                </div>
            @endforeach
        </div>

        <div class="pagination">
            {{ $products->links() }}
        </div>
    @else
        <p>Товары не найдены. <a href="{{ route('products.index') }}?moysklad">Загрузить из МойСклад</a></p>
    @endif
</div>
</body>
</html>
