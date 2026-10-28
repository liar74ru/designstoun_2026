<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SkuColorRequest;
use App\Services\SkuColorService;
use Illuminate\Http\RedirectResponse;

class SkuColorController extends Controller
{
    public function __construct(private SkuColorService $service)
    {
    }

    public function update(SkuColorRequest $request): RedirectResponse
    {
        $this->service->sync($request->validated('rows') ?? []);

        return redirect()->route('admin.settings.sku-colors')
            ->with('success', 'Цвета по SKU сохранены.');
    }
}
