<x-guest-layout>
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf

        {{-- Поле: телефон или email --}}
        <div>
            <x-input-label for="login" value="Телефон или Email" />
            <x-text-input
                id="login"
                class="block mt-1 w-full"
                type="text"
                name="login"
                :value="old('login')"
                required
                autofocus
                autocomplete="username"
                placeholder="+7 999 123-45-67"
            />
            <x-input-error :messages="$errors->get('login')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" class="block mt-1 w-full"
                          type="password"
                          name="password"
                          required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="block mt-4">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox"
                       class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                       name="remember">
                <span class="ms-2 text-sm text-gray-600">Запомнить меня</span>
            </label>
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-primary-button class="ms-3">Войти</x-primary-button>
        </div>
    </form>
</x-guest-layout>
