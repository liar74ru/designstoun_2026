<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Работника перевели в архив, пока он в системе: обрываем сессию и токен «запомнить меня». */
class EnsureLoginAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isLoginBlocked()) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['login' => 'Учётная запись в архиве. Обратитесь к администратору.']);
        }

        return $next($request);
    }
}
