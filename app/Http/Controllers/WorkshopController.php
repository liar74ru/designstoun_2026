<?php

namespace App\Http\Controllers;

use App\Http\Requests\Workshop\StoreWorkshopRequest;
use App\Http\Requests\Workshop\UpdateWorkshopRequest;
use App\Models\Workshop;
use App\Models\Worker;
use App\Services\WorkshopService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Контроллер модуля «Цех».
 *
 * Архитектурное правило проекта: controller → service → service/moysklad.
 * Поэтому контроллер инъектит ТОЛЬКО WorkshopService — все обращения к
 * МойСклад идут через сервис.
 */
class WorkshopController extends Controller
{
    public function __construct(
        private WorkshopService $service,
    ) {}

    public function index(Request $request): View
    {
        $workshops = $this->service->getFilteredWorkshops($request);
        [
            'filterProducts'        => $filterProducts,
            'filterPackers'         => $filterPackers,
            'filterPackageProducts' => $filterPackageProducts,
            'filterDepartments'     => $filterDepartments,
            'departmentDefaults'    => $departmentDefaults,
        ] = $this->service->getFilterData($request);

        return view('workshops.index', compact(
            'workshops', 'filterProducts', 'filterPackers', 'filterPackageProducts',
            'filterDepartments', 'departmentDefaults'
        ));
    }

    public function create(Request $request): View
    {
        $data = $this->service->getFormOptions();
        $data['selectedPackerId'] = $request->input('packer_id');
        $data['lastWorkshops']    = $this->service->getLastWorkshops($request);

        $copyItems = [];
        if ($copyFromId = $request->input('copy_from')) {
            $copyFrom = Workshop::with('items.product')->find($copyFromId);
            if ($copyFrom) {
                $copyItems = $copyFrom->items->map(fn($item) => [
                    'role'          => $item->role,
                    'product_id'    => $item->product_id,
                    'product_label' => $item->product?->name ?? '',
                ])->values()->toArray();
            }
        }
        $data['copyItems'] = $copyItems;

        return view('workshops.create', $data);
    }

    public function store(StoreWorkshopRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $workshop = $this->service->create(
                $data,
                auth()->user()?->isAdmin() ?? false,
                $request->input('processing_name') ?: null
            );

            if ($request->boolean('close_workshop')) {
                $this->service->markCompleted($workshop);
                return redirect()->route('workshops.index')->with('success', 'Операция создана и закрыта.');
            }

            return redirect()->route('workshops.create', ['packer_id' => $request->input('packer_id')])
                ->with('success', 'Операция создана');

        } catch (\Exception $e) {
            Log::error('Ошибка создания операции цеха:', ['error' => $e->getMessage(), 'data' => $data]);
            return back()->withErrors(['error' => 'Ошибка: ' . $e->getMessage()])->withInput();
        }
    }

    public function show(Workshop $workshop): View
    {
        $workshop->load([
            'packer',
            'receiver',
            'store',
            'items.product',
            'workshopLogs' => fn($q) => $q->orderBy('created_at', 'asc'),
            'workshopLogs.items.product',
            'workshopLogs.packer',
            'workshopLogs.receiver',
        ]);

        $backUrl = back_url(route('workshops.index'));

        return view('workshops.show', compact('workshop', 'backUrl'));
    }

    public function edit(Workshop $workshop): View
    {
        $data = $this->service->getFormOptions($workshop);

        return view('workshops.edit', $data);
    }

    public function update(UpdateWorkshopRequest $request, Workshop $workshop): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->service->update($workshop, $data, auth()->user()?->isAdmin() ?? false);

            if ($request->boolean('close_workshop')) {
                $result = $this->service->markCompleted($workshop);
                return redirect()->route('workshops.index')->with(
                    $result['success'] ? 'success' : 'warning',
                    $result['message']
                );
            }

            return redirect()->route('workshops.index')->with('success', 'Операция обновлена');

        } catch (\Exception $e) {
            Log::error('Ошибка обновления операции цеха:', ['error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Ошибка: ' . $e->getMessage()])->withInput();
        }
    }

    public function destroy(Workshop $workshop): RedirectResponse
    {
        try {
            $this->service->delete($workshop);
            return redirect()->route('workshops.index')->with('success', 'Операция удалена');
        } catch (\Exception $e) {
            Log::error('Ошибка удаления операции цеха:', ['error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Ошибка удаления']);
        }
    }

    public function copy(Workshop $workshop): RedirectResponse
    {
        return redirect()->route('workshops.create', [
            'copy_from' => $workshop->id,
            'packer_id' => $workshop->packer_id,
        ]);
    }

    public function syncToProcessing(Workshop $workshop): RedirectResponse
    {
        $result = $this->service->syncToProcessing($workshop);

        return $result['success']
            ? back()->with('success', $result['message'])
            : back()->with('error',   $result['message']);
    }

    public function resetStatus(Workshop $workshop): RedirectResponse
    {
        $result = $this->service->resetStatus($workshop);

        if ($result !== true) {
            return back()->with('error', $result);
        }

        return back()->with('success', 'Статус сброшен на Активна');
    }

    public function markCompleted(Workshop $workshop): RedirectResponse
    {
        abort_unless($workshop->status === Workshop::STATUS_ACTIVE, 403, 'Закрыть можно только активную операцию');

        $result = $this->service->markCompleted($workshop);

        if (!$result['success']) {
            return back()->with('warning', $result['message']);
        }

        return back()->with('success', $result['message']);
    }

    public function updateItemCoeff(Request $request, Workshop $workshop): RedirectResponse
    {
        $validated = $request->validate([
            'items'               => ['required', 'array'],
            'items.*.item_id'     => ['required', 'integer'],
            'items.*.base_coeff'  => ['required', 'numeric'],
            'items.*.is_undercut' => ['nullable', 'boolean'],
            'items.*.is_edging'   => ['nullable', 'boolean'],
        ]);

        $this->service->updateItemCoeff($workshop, $validated);

        return back()->with('success', 'Коэффициенты обновлены');
    }

    public function refreshItemCoeffs(Workshop $workshop): RedirectResponse
    {
        $this->service->refreshItemCoeffs($workshop);

        return back()->with('success', 'Коэффициенты обновлены из справочника');
    }

    /**
     * AJAX: получить дефолтный склад производства для работника.
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
