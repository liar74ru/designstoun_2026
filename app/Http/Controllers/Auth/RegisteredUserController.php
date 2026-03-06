<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        // Нормализуем телефон — оставляем только цифры,
        // чтобы в БД всегда хранился единый формат
        $phone = preg_replace('/\D/', '', $request->input('phone', ''));

        $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'phone'    => ['required', 'string'],
            'email'    => ['nullable', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Проверяем уникальность нормализованного телефона отдельно —
        // стандартный unique:users не знает о нормализации
        if (User::where('phone', $phone)->exists()) {
            return back()
                ->withInput()
                ->withErrors(['phone' => 'Этот номер телефона уже зарегистрирован.']);
        }

        // Ищем работника с таким же телефоном, чтобы сразу привязать аккаунт.
        // Сравниваем нормализованные номера.
        $worker = Worker::all()->first(function ($w) use ($phone) {
            return preg_replace('/\D/', '', (string) $w->phone) === $phone;
        });

        $user = User::create([
            'name'      => $request->name,
            'email'     => $request->email ?: null,
            'phone'     => $phone,
            'password'  => Hash::make($request->password),
            'worker_id' => $worker?->id,
            // Первый зарегистрированный пользователь становится администратором
            'is_admin'  => User::count() === 0,
        ]);

        event(new Registered($user));
        Auth::login($user);

        // Если нашли связанного работника — на его страницу,
        // иначе на общий дашборд
        if ($user->worker_id && ! $user->isAdmin()) {
            return redirect()->route('worker.dashboard');
        }

        return redirect()->route('dashboard');
    }
}
