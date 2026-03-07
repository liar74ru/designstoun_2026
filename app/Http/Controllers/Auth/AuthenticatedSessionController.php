<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        $user = Auth::user();

        // Было: $user->worker->position  ← падает если worker = null (администратор)
        // Стало: безопасная проверка через ?->
        if ($user->worker?->position === 'Пильщик') {
            return redirect()->route('worker.dashboard');
        }

        // Если приёмщик — тоже редиректим на его страницу (добавь если нужно)
        // if ($user->worker?->position === 'Приемщик') {
        //     return redirect()->route('reception.dashboard');
        // }

        if ($user->isAdmin()) {
            return redirect()->intended(route('home'));
        }

        return redirect()->intended(route('home'));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
