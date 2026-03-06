<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // login — телефон или email
            'login'    => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Определяем, что ввёл пользователь — телефон или email.
     * Телефон: содержит только цифры, +, -, пробелы (нет @)
     */
    private function getLoginField(): string
    {
        return str_contains($this->input('login'), '@') ? 'email' : 'phone';
    }

    /**
     * Нормализуем телефон: оставляем только цифры.
     * +7 (999) 123-45-67  →  79991234567
     * Это позволяет вводить в любом формате.
     */
    private function normalizeLogin(string $login): string
    {
        if ($this->getLoginField() === 'phone') {
            return preg_replace('/\D/', '', $login);
        }
        return $login;
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $field = $this->getLoginField();
        $login = $this->normalizeLogin($this->input('login'));

        if (! Auth::attempt(
            [$field => $login, 'password' => $this->input('password')],
            $this->boolean('remember')
        )) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'login' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('login')) . '|' . $this->ip());
    }
}
