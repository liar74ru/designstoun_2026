<?php

/**
 * Разметка форм в шаблонах (правило CLAUDE.md, H1 в 4.0.43).
 *
 * Форма, изменяющая данные, помечается data-submit-guard: иначе двойное нажатие
 * отправит её дважды. Подтверждение — атрибутом data-confirm, а не onsubmit:
 * данные внутри on*= браузер раскодирует и выполнит как код.
 */

/**
 * Формы без guard. Не меняют данные или отправляются скриптом через form.submit(),
 * при котором событие submit не возникает и guard ничего бы не сделал.
 * Ключ — «шаблон#id», для формы без id — «шаблон».
 */
const GUARD_EXEMPT = [
    'auth/login.blade.php',
    'layouts/partials/header.blade.php#logout-form',
    'workers/edit-user.blade.php#logout-form-profile',
    'orders/partials/position-modal.blade.php#positionResetForm',
    'supplier-orders/create.blade.php#sync-cp-form',
];

/**
 * Открывающие теги <form> всех шаблонов: [шаблон, строка, тег].
 * Тег часто занимает несколько строк, и `>` внутри {{ }} или кавычек его не закрывает.
 *
 * @return list<array{string, int, string}>
 */
function viewFormTags(): array
{
    $root  = dirname(__DIR__, 2) . '/resources/views/';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $tags  = [];

    foreach ($files as $file) {
        if (!str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $s   = file_get_contents($file->getPathname());
        $rel = substr($file->getPathname(), strlen($root));

        preg_match_all('/<form\b/', $s, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [, $start]) {
            $i     = $start + 5;
            $depth = 0;
            $quote = null;

            for ($len = strlen($s); $i < $len; $i++) {
                if (substr($s, $i, 2) === '{{') {
                    $depth++;
                    $i++;

                    continue;
                }
                if (substr($s, $i, 2) === '}}') {
                    $depth--;
                    $i++;

                    continue;
                }
                if ($depth > 0) {
                    continue;
                }
                if ($quote !== null) {
                    $quote = $s[$i] === $quote ? null : $quote;
                } elseif ($s[$i] === '"' || $s[$i] === "'") {
                    $quote = $s[$i];
                } elseif ($s[$i] === '>') {
                    break;
                }
            }

            $tags[] = [$rel, substr_count($s, "\n", 0, $start) + 1, substr($s, $start, $i - $start + 1)];
        }
    }

    return $tags;
}

test('каждая изменяющая форма помечена data-submit-guard', function () {
    $missing = [];

    foreach (viewFormTags() as [$view, $line, $tag]) {
        $method = preg_match('/\bmethod="?(\w+)/i', $tag, $m) ? strtolower($m[1]) : 'get';
        if ($method === 'get' || str_contains($tag, 'data-submit-guard')) {
            continue;
        }

        $key = preg_match('/\bid="([^"]+)"/', $tag, $m) ? "{$view}#{$m[1]}" : $view;
        if (!in_array($key, GUARD_EXEMPT, true)) {
            $missing[] = "{$view}:{$line}";
        }
    }

    expect($missing)->toBe([], 'Форма без data-submit-guard (или добавьте её в GUARD_EXEMPT с причиной)');
});

test('исключения из guard существуют — список не устаревает', function () {
    $present = collect(viewFormTags())
        ->map(fn (array $t) => preg_match('/\bid="([^"]+)"/', $t[2], $m) ? "{$t[0]}#{$m[1]}" : $t[0])
        ->all();

    expect(array_values(array_diff(GUARD_EXEMPT, $present)))->toBe([]);
});

test('подтверждение формы — data-confirm, а не onsubmit', function () {
    $inline = collect(viewFormTags())
        ->filter(fn (array $t) => preg_match('/\sonsubmit\s*=/i', $t[2]))
        ->map(fn (array $t) => "{$t[0]}:{$t[1]}")
        ->values()
        ->all();

    expect($inline)->toBe([]);
});
