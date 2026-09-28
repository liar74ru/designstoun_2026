<?php

namespace App\Services;

use App\Models\User;
use App\Support\OperationAccessor;
use Illuminate\Support\Str;
use InvalidArgumentException;
use League\CommonMark\Extension\Table\TableExtension;

/**
 * Справка в приложении — рендер документов из docs/.
 *
 * Один файл читают и разработчики в репозитории, и пользователи в приложении:
 * текст не дублируется. Раздел «Для разработчиков» (с именами классов и тестов)
 * пользователю не показывается — всё начиная с этого заголовка отрезается.
 *
 * Перекрёстные ссылки в документах:
 *   [Сырьё](raw-batches.md#якорь) — другая страница справки (в репозитории — обычная ссылка);
 *   [Статусы заявок](app:order-states) — экран приложения из LINKS.
 * Нет доступа к цели — остаётся текст ссылки, чтобы мастер не упирался в 403.
 */
class HelpService
{
    /**
     * Страницы справки. Доступ — как к любой из операций реестра (OperationAccessor):
     * псевдо-операцию «справка» не заводим, иначе она попала бы в шапку и матрицу прав.
     */
    public const PAGES = [
        'orders' => [
            'title'      => 'Заявки',
            'icon'       => 'bi-clipboard-check',
            'file'       => 'orders.md',
            'operations' => ['orders'],
            'back'       => 'orders.index',
        ],
        'stone-receptions' => [
            'title'      => 'Приём',
            'icon'       => 'bi-box-seam',
            'file'       => 'stone-receptions.md',
            'operations' => ['stone-receptions'],
            'back'       => 'stone-receptions.index',
        ],
        'workshops' => [
            'title'      => 'Цех',
            'icon'       => 'bi-building-gear',
            'file'       => 'workshops.md',
            'operations' => ['workshops'],
            'back'       => 'workshops.index',
        ],
        'pay' => [
            'title'      => 'Выработка и ставки',
            'icon'       => 'bi-cash-coin',
            'file'       => 'pay.md',
            'operations' => ['worker-dashboard', 'master-dashboard', 'stone-receptions', 'workshops'],
            'back'       => null,
        ],
        'raw-batches' => [
            'title'      => 'Сырьё',
            'icon'       => 'bi-bricks',
            'file'       => 'raw-batches.md',
            'operations' => ['raw-batches'],
            'back'       => 'raw-batches.index',
        ],
        'supplier-orders' => [
            'title'      => 'Приход',
            'icon'       => 'bi-truck',
            'file'       => 'supplier-orders.md',
            'operations' => ['supplier-orders'],
            'back'       => 'supplier-orders.index',
        ],
        'products' => [
            'title'      => 'Товары',
            'icon'       => 'bi-tags',
            'file'       => 'products.md',
            'operations' => ['products'],
            'back'       => 'products.index',
        ],
        'enterprise-dashboard' => [
            'title'      => 'Итоги',
            'icon'       => 'bi-graph-up',
            'file'       => 'enterprise-dashboard.md',
            'operations' => ['enterprise-dashboard'],
            'back'       => 'admin.enterprise-dashboard',
        ],
    ];

    /**
     * Экраны приложения для ссылок `app:<ключ>`. Доступ — операция реестра
     * или только админ (`operation => null`).
     */
    public const LINKS = [
        'settings'             => ['route' => 'admin.settings.index',       'operation' => null],
        'order-states'         => ['route' => 'admin.order-states.index',   'operation' => null],
        'orders'               => ['route' => 'orders.index',               'operation' => 'orders'],
        'stone-receptions'     => ['route' => 'stone-receptions.index',     'operation' => 'stone-receptions'],
        'workshops'            => ['route' => 'workshops.index',            'operation' => 'workshops'],
        'raw-batches'          => ['route' => 'raw-batches.index',          'operation' => 'raw-batches'],
        'supplier-orders'      => ['route' => 'supplier-orders.index',      'operation' => 'supplier-orders'],
        'products'             => ['route' => 'products.index',             'operation' => 'products'],
        'enterprise-dashboard' => ['route' => 'admin.enterprise-dashboard', 'operation' => 'enterprise-dashboard'],
        'my-work'              => ['route' => 'worker.dashboard',           'operation' => 'worker-dashboard'],
        'master-work'          => ['route' => 'master.dashboard',           'operation' => 'master-dashboard'],
    ];

    private const DEVELOPER_HEADING = '## Для разработчиков';

    /** Ссылка markdown на другую страницу справки или на экран приложения. */
    private const LINK_PATTERN = '/\[([^\]]+)\]\((?:([a-z-]+)\.md|app:([a-z-]+))(#[^)\s]*)?\)/u';

    public function canSee(?User $user, string $page): bool
    {
        $operations = self::PAGES[$page]['operations'] ?? null;

        return $operations !== null && OperationAccessor::canSeeAny($user, $operations);
    }

    /** @return array<string, array<string, mixed>> доступные пользователю страницы, в порядке реестра */
    public function pagesFor(?User $user): array
    {
        return array_filter(self::PAGES, fn ($page) => $this->canSee($user, $page), ARRAY_FILTER_USE_KEY);
    }

    public function render(string $page, ?User $user): string
    {
        $markdown = Str::before($this->source($page), self::DEVELOPER_HEADING);

        $markdown = preg_replace_callback(self::LINK_PATTERN, function (array $m) use ($user) {
            $text     = $m[1];
            $helpPage = $m[2] ?? '';
            $appLink  = $m[3] ?? '';
            $anchor   = $m[4] ?? '';

            $url = $helpPage !== ''
                ? $this->helpUrl($helpPage, $user)
                : $this->appUrl($appLink, $user);

            return $url === null ? $text : "[{$text}]({$url}{$anchor})";
        }, $markdown);

        return Str::markdown($markdown, [
            'html_input'         => 'strip',
            'allow_unsafe_links' => false,
        ], [new TableExtension()]);
    }

    /**
     * Ключи ссылок, на которые нет записи в реестре: страницы и экраны.
     * Для теста — опечатка в документе не должна молча превращаться в текст.
     *
     * @return array<int, string>
     */
    public function brokenLinks(string $page): array
    {
        preg_match_all(self::LINK_PATTERN, $this->source($page), $matches, PREG_SET_ORDER);

        $broken = [];
        foreach ($matches as $m) {
            if (($m[2] ?? '') !== '' && ! isset(self::PAGES[$m[2]])) {
                $broken[] = $m[2] . '.md';
            }
            if (($m[3] ?? '') !== '' && ! isset(self::LINKS[$m[3]])) {
                $broken[] = 'app:' . $m[3];
            }
        }

        return $broken;
    }

    private function source(string $page): string
    {
        $file = self::PAGES[$page]['file'] ?? throw new InvalidArgumentException("Нет страницы справки «{$page}».");

        return (string) file_get_contents(base_path('docs/' . $file));
    }

    private function helpUrl(string $page, ?User $user): ?string
    {
        return $this->canSee($user, $page) ? route('help.show', $page) : null;
    }

    private function appUrl(string $key, ?User $user): ?string
    {
        $link = self::LINKS[$key] ?? null;
        if ($link === null) {
            return null;
        }

        $allowed = $link['operation'] === null
            ? (bool) $user?->isAdmin()
            : OperationAccessor::canSee($user, $link['operation']);

        return $allowed ? route($link['route']) : null;
    }
}
