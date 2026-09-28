<?php

namespace App\Http\Controllers;

use App\Services\HelpService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Справка по разделам — документы из docs/ (см. HelpService). */
class HelpController extends Controller
{
    public function __construct(private HelpService $help)
    {
    }

    public function index(Request $request): View
    {
        return view('help.index', ['pages' => $this->help->pagesFor($request->user())]);
    }

    public function show(Request $request, string $page): View
    {
        abort_unless(isset(HelpService::PAGES[$page]), 404);
        abort_unless($this->help->canSee($request->user(), $page), 403);

        $meta = HelpService::PAGES[$page];

        return view('help.show', [
            'page' => $page,
            'meta' => $meta,
            'html' => $this->help->render($page, $request->user()),
        ]);
    }
}
