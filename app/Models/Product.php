<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'moysklad_id',
        'group_id',
        'group_name',
        'name',
        'sku',
        'uom',
        'description',
        'price',
        'old_price',
        'min_price',
        'buy_price',
        'prod_cost_coeff',
        'master_cost_coeff',
        'is_active',
        'attributes',
    ];

    protected $casts = [
        'price'           => 'decimal:2',
        'old_price'       => 'decimal:2',
        'min_price'       => 'decimal:2',
        'buy_price'       => 'decimal:2',
        'prod_cost_coeff' => 'decimal:4',
        // Коэффициент ставки мастера (атрибут masterCostCoeff МойСклад).
        // 0 (в т.ч. когда атрибут не заведён или не заполнен) = базовая ставка без надбавки.
        'master_cost_coeff' => 'decimal:4',
        'is_active'       => 'boolean',
        'attributes'      => 'array',
    ];

    /**
     * Базовая ставка пильщика отдела (₽/ед).
     * Отдел не передан или не задал свою ставку → глобальное значение.
     */
    public static function pieceRate(?int $departmentId = null): float
    {
        return \App\Support\DepartmentSettings::pieceRate($departmentId);
    }

    /** Карта «префикс → цвет» на время запроса: getColorBySku() зовётся в каждой строке списков. */
    private static ?array $skuColorMemo = null;

    /**
     * Возвращает цвет по SKU продукта.
     * Сначала ищется префикс из двух сегментов ("01-05"), затем цвет группы — первый сегмент ("01").
     * Цвета — таблица sku_colors (правит админ в «Настройках»). Не нашлось — белый (#FFFFFF).
     */
    public static function getColorBySku(?string $sku): string
    {
        if (!$sku) return '#FFFFFF';
        $parts = explode('-', $sku);
        self::$skuColorMemo ??= SkuColor::map();
        if (count($parts) >= 2 && isset(self::$skuColorMemo[$parts[0] . '-' . $parts[1]])) {
            return self::$skuColorMemo[$parts[0] . '-' . $parts[1]];
        }
        return self::$skuColorMemo[$parts[0]] ?? '#FFFFFF';
    }

    public static function forgetSkuColorMemo(): void
    {
        self::$skuColorMemo = null;
    }

    /**
     * Имена Ionicons-иконок категорий камня по первому сегменту SKU.
     * Ключ — первая часть SKU до дефиса ("01".."05").
     */
    const SKU_ICONS = [
        '01' => 'server-sharp',   // Плитняк (бесформенные плитки)
        '02' => 'apps-outline',   // Щебень
        '03' => 'cloud-sharp',    // Валуны
        '04' => 'grid-outline',   // Плитка из камня
        '05' => 'flame-sharp',    // Камень для бани
    ];

    /**
     * Возвращает имя Ionicons-иконки по SKU (для <ion-icon name="…">).
     * Сопоставление по первому сегменту SKU ("01".."05").
     * Для неизвестных SKU — "cube-outline" как нейтральный fallback.
     */
    public static function getIconBySku(?string $sku): string
    {
        if (!$sku) return 'cube-outline';
        $prefix = explode('-', $sku, 2)[0];
        return self::SKU_ICONS[$prefix] ?? 'cube-outline';
    }

    // ─── Связи ───────────────────────────────────────────────────────────────

    public function group()
    {
        return $this->belongsTo(ProductGroup::class, 'group_id', 'moysklad_id');
    }

    /**
     * Остатки по складам. Единственный источник истины для количества.
     */
    public function stocks()
    {
        return $this->hasMany(ProductStock::class, 'product_id');
    }

    // ─── Аксессоры ───────────────────────────────────────────────────────────

    /**
     * Суммарный остаток по всем складам.
     * Используй ->withSum('stocks', 'quantity') для оптимизации в списках.
     */
    public function getTotalQuantityAttribute(): float
    {
        // Если уже загружена агрегация через withSum — берём её
        if (array_key_exists('stocks_sum_quantity', $this->attributes)) {
            return (float) $this->attributes['stocks_sum_quantity'];
        }
        // Иначе считаем из загруженной коллекции или делаем запрос
        return (float) $this->stocks->sum('quantity');
    }

    public function getInStockAttribute(): bool
    {
        return $this->total_quantity > 0;
    }

    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->price, 2, ',', ' ') . ' ₽';
    }

    public function getHasDiscountAttribute(): bool
    {
        return $this->old_price && $this->old_price > $this->price;
    }

    public function getDiscountPercentAttribute(): ?int
    {
        if (!$this->has_discount) return null;
        return round((($this->old_price - $this->price) / $this->old_price) * 100);
    }

    /**
     * Возвращает цену закупки для передачи в МойСклад:
     * minPrice если задана и > 0, иначе buyPrice.
     * Результат в рублях (float).
     */
    public function effectiveBuyPrice(): float
    {
        $min = (float) ($this->min_price ?? 0);
        $buy = (float) ($this->buy_price ?? 0);
        return $min > 0 ? $min : $buy;
    }

    // ─── Бизнес-логика ───────────────────────────────────────────────────────

    /**
     * Стоимость единицы продукции для пильщика (₽/ед).
     *
     * @param int|null $departmentId Отдел документа; null → глобальная ставка
     */
    public function prodCost($coeffCustom = null, ?int $departmentId = null): float|int
    {
        // ОКРУГЛВНИЗ((PIECE_RATE + PIECE_RATE*17% * coeff) / 10; 0) * 10
        $coeff = $coeffCustom ?? (float)$this->prod_cost_coeff ?? 0;

        return \App\Support\RateFormula::stepped(self::pieceRate($departmentId), (float) $coeff);
    }

    public function calculateWorkerPay(float $quantity, ?int $departmentId = null): float
    {
        return $quantity * $this->prodCost(null, $departmentId);
    }
}
