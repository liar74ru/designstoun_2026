<x-guest-layout>
    <form method="POST" action="{{ route('register') }}">
        @csrf

        {{-- Имя --}}
        <div>
            <x-input-label for="name" value="Имя" />
            <x-text-input id="name" class="block mt-1 w-full"
                          type="text" name="name" :value="old('name')"
                          required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        {{-- Телефон (обязательный — используется для входа) --}}
        <div class="mt-4">
            <x-input-label for="phone" value="Телефон" />
            <x-text-input id="phone" class="block mt-1 w-full"
                          type="text" name="phone" :value="old('phone')"
                          required autocomplete="tel"
                          placeholder="+7 999 123-45-67" />
            <p class="mt-1 text-sm text-gray-500">Используется для входа в систему</p>
            <x-input-error :messages="$errors->get('phone')" class="mt-2" />
        </div>

        {{-- Email (необязательный) --}}
        <div class="mt-4">
            <x-input-label for="email" value="Email (необязательно)" />
            <x-text-input id="email" class="block mt-1 w-full"
                          type="email" name="email" :value="old('email')"
                          autocomplete="email" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        {{-- Пароль --}}
        <div class="mt-4">
            <x-input-label for="password" value="Пароль" />
            <x-text-input id="password" class="block mt-1 w-full"
                          type="password" name="password"
                          required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        {{-- Подтверждение пароля --}}
        <div class="mt-4">
            <x-input-label for="password_confirmation" value="Подтвердите пароль" />
            <x-text-input id="password_confirmation" class="block mt-1 w-full"
                          type="password" name="password_confirmation"
                          required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
               href="{{ route('login') }}">
                Уже есть аккаунт?
            </a>
            <x-primary-button class="ms-4">Зарегистрироваться</x-primary-button>
        </div>
    </form>
</x-guest-layout>
