<?php

namespace App\Http\Controllers;

use App\Models\Counterparty;
use App\Services\MoySkladService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CounterpartyController extends Controller
{
    public function __construct(private MoySkladService $moySkladService)
    {
    }

    public function index(): View
    {
        $counterparties = Counterparty::orderBy('name')->get();

        return view('counterparties.index', compact('counterparties'));
    }

    public function sync(): RedirectResponse
    {
        $result = $this->moySkladService->syncCounterparties();

        if ($result['success']) {
            return redirect()->route('counterparties.index')
                ->with('success', 'Контрагенты синхронизированы: ' . $result['message']);
        }

        return redirect()->route('counterparties.index')
            ->with('error', $result['message']);
    }
}
