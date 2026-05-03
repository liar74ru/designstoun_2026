<?php

namespace App\Http\Controllers;

use App\Http\Requests\Packaging\StorePackagingRequest;
use App\Http\Requests\Packaging\UpdatePackagingRequest;
use App\Models\Packaging;
use App\Models\Worker;
use App\Services\PackagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Контроллер модуля «Упаковка».
 *
 * Архитектурное правило проекта: controller → service → service/moysklad.
 * Поэтому контроллер инъектит ТОЛЬКО PackagingService — все обращения к
 * МойСклад идут через сервис.
 */
class PackagingController extends Controller
{
    public function __construct(
        private PackagingService $service,
    ) {}

    public function index(Request $request): View
    {
        $packagings = $this->service->getFilteredPackagings($request);
        [
            'filterProducts'        => $filterProducts,
            'filterPackers'         => $filterPackers,
            'filterPackageProducts' => $filterPackageProducts,
        ] = $this->service->getFilterData();

        return view('packagings.index', compact(
            'packagings', 'filterProducts', 'filterPackers', 'filterPackageProducts'
        ));
    }

    public function create(Request $request): View
    {
        $packerId = $request->input('packer_id');

        $data = $this->service->getFormOptions();
        $data['selectedPackerId'] = $packerId;

        if ($packerId) {
            $packer = Worker::find($packerId);
            $data['defaultStore'] = $this->service->getDefaultStoreForPacker($packer);
        }

        return view('packagings.create', $data);
    }

    public function store(StorePackagingRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $packaging = $this->service->create(
                $data,
                auth()->user()?->isAdmin() ?? false,
                $request->input('processing_name') ?: null
            );

            if ($request->boolean('close_packaging')) {
                $this->service->markCompleted($packaging);
                return redirect()->route('packagings.index')->with('success', 'Упаковка создана и закрыта.');
            }

            return redirect()->route('packagings.create', ['packer_id' => $request->input('packer_id')])
                ->with('success', 'Упаковка создана');

        } catch (\Exception $e) {
            Log::error('Ошибка создания упаковки:', ['error' => $e->getMessage(), 'data' => $data]);
            return back()->withErrors(['error' => 'Ошибка: ' . $e->getMessage()])->withInput();
        }
    }

    public function show(Packaging $packaging): View
    {
        $packaging->load([
            'packer',
            'receiver',
            'store',
            'items.product',
            'packageProduct',
            'packagingLogs' => fn($q) => $q->orderBy('created_at', 'asc'),
            'packagingLogs.items.product',
            'packagingLogs.packer',
            'packagingLogs.receiver',
        ]);

        $backUrl = back_url(route('packagings.index'));

        return view('packagings.show', compact('packaging', 'backUrl'));
    }

    public function edit(Packaging $packaging): View
    {
        $data = $this->service->getFormOptions($packaging);
        $data['defaultStore'] = $this->service->getDefaultStoreForPacker($packaging->packer);

        return view('packagings.edit', $data);
    }

    public function update(UpdatePackagingRequest $request, Packaging $packaging): RedirectResponse
    {
        $data = $request->validated();
        $data['package_quantity_delta'] = (float) $request->input('package_quantity_delta', 0);

        try {
            $this->service->update($packaging, $data, auth()->user()?->isAdmin() ?? false);

            if ($request->boolean('close_packaging')) {
                $result = $this->service->markCompleted($packaging);
                return redirect()->route('packagings.index')->with(
                    $result['success'] ? 'success' : 'warning',
                    $result['message']
                );
            }

            return redirect()->route('packagings.index')->with('success', 'Упаковка обновлена');

        } catch (\Exception $e) {
            Log::error('Ошибка обновления упаковки:', ['error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Ошибка: ' . $e->getMessage()])->withInput();
        }
    }

    public function destroy(Packaging $packaging): RedirectResponse
    {
        try {
            $this->service->delete($packaging);
            return redirect()->route('packagings.index')->with('success', 'Упаковка удалена');
        } catch (\Exception $e) {
            Log::error('Ошибка удаления упаковки:', ['error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Ошибка удаления']);
        }
    }

    public function copy(Packaging $packaging): RedirectResponse
    {
        return redirect()->route('packagings.create', [
            'copy_from' => $packaging->id,
            'packer_id' => $packaging->packer_id,
        ]);
    }

    public function syncToProcessing(Packaging $packaging): RedirectResponse
    {
        $result = $this->service->syncToProcessing($packaging);

        return $result['success']
            ? back()->with('success', $result['message'])
            : back()->with('error',   $result['message']);
    }

    public function resetStatus(Packaging $packaging): RedirectResponse
    {
        $result = $this->service->resetStatus($packaging);

        if ($result !== true) {
            return back()->with('error', $result);
        }

        return back()->with('success', 'Статус сброшен на Активна');
    }

    public function markCompleted(Packaging $packaging): RedirectResponse
    {
        abort_unless($packaging->status === Packaging::STATUS_ACTIVE, 403, 'Закрыть можно только активную упаковку');

        $result = $this->service->markCompleted($packaging);

        if (!$result['success']) {
            return back()->with('warning', $result['message']);
        }

        return back()->with('success', $result['message']);
    }

    public function updateItemCoeff(Request $request, Packaging $packaging): RedirectResponse
    {
        $validated = $request->validate([
            'items'               => ['required', 'array'],
            'items.*.item_id'     => ['required', 'integer'],
            'items.*.base_coeff'  => ['required', 'numeric'],
            'items.*.is_undercut' => ['nullable', 'boolean'],
        ]);

        $this->service->updateItemCoeff($packaging, $validated);

        return back()->with('success', 'Коэффициенты обновлены');
    }

    public function refreshItemCoeffs(Packaging $packaging): RedirectResponse
    {
        $this->service->refreshItemCoeffs($packaging);

        return back()->with('success', 'Коэффициенты обновлены из справочника');
    }

    /**
     * AJAX: получить дефолтный склад производства для упаковщика.
     */
    public function getDefaultStoreJson(Worker $worker): JsonResponse
    {
        $store = $this->service->getDefaultStoreForPacker($worker);

        return response()->json($store ? [
            'id'   => $store->id,
            'name' => $store->name,
        ] : null);
    }
}
