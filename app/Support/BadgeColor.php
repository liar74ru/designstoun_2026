<?php

namespace App\Support;

class BadgeColor
{
    /** Цвет плашки, когда собственный не задан. */
    public const FALLBACK = '#6c757d';

    /**
     * Цвет текста на цветной плашке: на жёлтом и голубом белые буквы не читаются.
     * Яркость по формуле 0.299R + 0.587G + 0.114B; зеркало textColorOn()
     * из resources/js/modifier-picker.js.
     */
    public static function textFor(?string $background): string
    {
        $hex = ltrim($background ?: self::FALLBACK, '#');

        if (strlen($hex) !== 6) {
            return '#FFFFFF';
        }

        [$r, $g, $b] = array_map(hexdec(...), str_split($hex, 2));

        // Порог 140: жёлтый и голубой уходят на тёмный текст — ровно так же,
        // как раньше их бейджи носили класс text-dark.
        return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 140 ? '#212529' : '#FFFFFF';
    }

    /**
     * Цвет МойСклад (RGB числом) → hex. Старший байт может нести альфу — отбрасываем.
     */
    public static function fromMoysklad(?int $color): ?string
    {
        return $color === null ? null : sprintf('#%06X', $color & 0xFFFFFF);
    }
}
