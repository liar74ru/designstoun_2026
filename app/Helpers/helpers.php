<?php

if (! function_exists('back_url')) {
    /**
     * Вернуть URL предыдущей страницы, защищая от рефрешей.
     * Если предыдущий URL совпадает с текущим (прямой заход / F5),
     * возвращается $fallback.
     */
    function back_url(string $fallback): string
    {
        $previous = url()->previous($fallback);

        if (rtrim($previous, '/') === rtrim(url()->current(), '/')) {
            return $fallback;
        }

        return $previous;
    }
}
