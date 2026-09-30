<?php

namespace App\Support;

/**
 * Кнопка «Назад» ведёт на страницу, с которой пришли в этой вкладке (resources/js/back-link.js).
 * Формы — «проходные» страницы: после сохранения на них не возвращаются, поэтому «Назад»
 * их пропускает. Сервер помечает такие страницы мета-тегом `nav-transient` в раскладке.
 */
class BackNavigation
{
    /** Последний сегмент имени маршрута, по которому страница считается формой. */
    public const TRANSIENT_SEGMENTS = ['create', 'edit', 'form', 'create-user', 'edit-user', 'sync-confirm'];

    public static function isTransient(?string $routeName): bool
    {
        if ($routeName === null) {
            return false;
        }

        $segments = explode('.', $routeName);

        return in_array(end($segments), self::TRANSIENT_SEGMENTS, true);
    }
}
