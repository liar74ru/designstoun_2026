<?php

use App\Services\HelpService;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

// ══════════════════════════════════════════════════════════════════════════════
// Справка по разделам — docs/*.md через HelpService
// ══════════════════════════════════════════════════════════════════════════════

$pages = array_keys(HelpService::PAGES);

describe('страницы справки', function () use ($pages) {

    test('админ открывает страницу, раздела для разработчиков нет', function (string $page) {
        $this->actingAs(H::adminUser())
            ->get(route('help.show', $page))
            ->assertSuccessful()
            ->assertViewIs('help.show')
            ->assertSee(HelpService::PAGES[$page]['title'])
            ->assertDontSee('Для разработчиков')
            ->assertDontSee('tests/Feature');
    })->with($pages);

    test('ссылки в документе ведут на существующие страницы и экраны', function (string $page) {
        expect(app(HelpService::class)->brokenLinks($page))->toBe([]);
    })->with($pages);

    test('неизвестная страница — 404', function () {
        $this->actingAs(H::adminUser())
            ->get('/help/nope')
            ->assertNotFound();
    });

    test('таблицы markdown рендерятся таблицами', function () {
        expect(app(HelpService::class)->render('orders', H::adminUser()))->toContain('<table>');
    });

    test('страница вне реестра не читается', function () {
        app(HelpService::class)->render('../.env', null);
    })->throws(InvalidArgumentException::class);
});

describe('доступ', function () {

    test('мастер с операцией раздела открывает справку', function () {
        $this->actingAs(Access::master(Access::department(), 'orders'))
            ->get(route('help.show', 'orders'))
            ->assertSuccessful();
    });

    test('мастер без операции раздела получает 403', function () {
        $this->actingAs(Access::master(Access::department(), 'workshops'))
            ->get(route('help.show', 'orders'))
            ->assertForbidden();
    });

    test('работнику доступна справка по выработке', function () {
        $this->actingAs(Access::userWithPosition('Работник', Access::department()))
            ->get(route('help.show', 'pay'))
            ->assertSuccessful();
    });

    test('оглавление показывает только доступные страницы', function () {
        $this->actingAs(Access::userWithPosition('Работник', Access::department()))
            ->get(route('help.index'))
            ->assertSuccessful()
            ->assertSee(route('help.show', 'pay'))
            ->assertDontSee(route('help.show', 'orders'));
    });
});

describe('перекрёстные ссылки', function () {

    test('ссылка на доступную страницу — ссылка, на недоступную — текст', function () {
        $master = Access::master(Access::department(), 'orders');
        $html   = app(HelpService::class)->render('orders', $master);

        // orders.md ссылается на справку цеха; у мастера без «Цеха» — просто текст.
        expect($html)->not->toContain(route('help.show', 'workshops'))
            ->and($html)->toContain('выпуск цеха');

        $admin = app(HelpService::class)->render('orders', H::adminUser());
        expect($admin)->toContain(route('help.show', 'workshops'));
    });

    test('админский экран мастеру показывается текстом', function () {
        $master = Access::master(Access::department(), 'orders');

        expect(app(HelpService::class)->render('orders', $master))
            ->not->toContain(route('admin.order-states.index'))
            ->toContain('Статусы заявок');

        expect(app(HelpService::class)->render('orders', H::adminUser()))
            ->toContain(route('admin.order-states.index'));
    });
});

describe('кнопка «?» в разделах', function () {

    test('раздел ведёт на свою справку', function (string $route, string $page) {
        $this->actingAs(H::adminUser())
            ->get(route($route))
            ->assertSee(route('help.show', $page));
    })->with([
        'заявки'   => ['orders.index', 'orders'],
        'приём'    => ['stone-receptions.index', 'stone-receptions'],
        'цех'      => ['workshops.index', 'workshops'],
        'сырьё'    => ['raw-batches.index', 'raw-batches'],
        'приход'   => ['supplier-orders.index', 'supplier-orders'],
        'товары'   => ['products.index', 'products'],
    ]);

    test('шапка сайта ведёт на оглавление', function () {
        $this->actingAs(H::adminUser())
            ->get(route('orders.index'))
            ->assertSee(route('help.index'));
    });
});
