@extends('layouts.app')

@section('content')
    <div class="container mx-auto px-4 py-8">
        <!-- Back Button -->
        <div class="mb-6">
            <a href="{{ route('stores.index') }}" class="inline-flex items-center text-blue-600 hover:text-blue-800">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
                Вернуться к складам
            </a>
        </div>

        <!-- Header -->
        <div class="flex justify-between items-start mb-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">{{ $store->name }}</h1>
                @if ($store->path_name)
                    <p class="text-gray-600 mt-2">{{ $store->path_name }}</p>
                @endif
            </div>

            <div class="flex gap-2">
                @if ($store->archived)
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gray-100 text-gray-800">
                    Архивирован
                </span>
                @else
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                    Активен
                </span>
                @endif

                @if ($store->shared)
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                    Общий доступ
                </span>
                @endif
            </div>
        </div>

        <!-- Main Info -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
            <!-- Left Column -->
            <div class="lg:col-span-2">
                <!-- Store Details -->
                <div class="bg-white rounded-lg shadow p-6 mb-6">
                    <h2 class="text-xl font-bold text-gray-900 mb-6">Основная информация</h2>

                    <div class="space-y-4">
                        @if ($store->code)
                            <div>
                                <label class="text-sm font-medium text-gray-700">Код</label>
                                <p class="text-gray-900 mt-1">{{ $store->code }}</p>
                            </div>
                        @endif

                        @if ($store->external_code)
                            <div>
                                <label class="text-sm font-medium text-gray-700">Внешний код</label>
                                <p class="text-gray-900 mt-1">{{ $store->external_code }}</p>
                            </div>
                        @endif

                        @if ($store->address)
                            <div>
                                <label class="text-sm font-medium text-gray-700">Адрес</label>
                                <p class="text-gray-900 mt-1">{{ $store->address }}</p>
                            </div>
                        @endif

                        @if ($store->description)
                            <div>
                                <label class="text-sm font-medium text-gray-700">Описание</label>
                                <p class="text-gray-900 mt-1">{{ $store->description }}</p>
                            </div>
                        @endif

                        @if ($store->parent)
                            <div>
                                <label class="text-sm font-medium text-gray-700">Родительский склад</label>
                                <p class="text-gray-900 mt-1">{{ $store->parent->name }}</p>
                            </div>
                        @endif

                        <div>
                            <label class="text-sm font-medium text-gray-700">Последнее обновление</label>
                            <p class="text-gray-900 mt-1">{{ $store->updated_at->format('d.m.Y H:i') }}</p>
                        </div>
                    </div>
                </div>

                <!-- Address Details -->
                @if ($store->address_full)
                    <div class="bg-white rounded-lg shadow p-6">
                        <h2 class="text-xl font-bold text-gray-900 mb-6">Детальный адрес</h2>

                        <div class="grid grid-cols-2 gap-4">
                            @foreach ($store->address_full as $key => $value)
                                @if ($value)
                                    <div>
                                        <label class="text-sm font-medium text-gray-700 capitalize">{{ str_replace('_', ' ', $key) }}</label>
                                        <p class="text-gray-900 mt-1">{{ $value }}</p>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <!-- Right Column -->
            <div>
                <!-- Quick Info -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-bold text-gray-900 mb-6">Информация</h2>

                    <div class="space-y-4">
                        <div>
                            <label class="text-sm font-medium text-gray-700">ID</label>
                            <code class="block text-xs bg-gray-100 p-2 rounded mt-1 text-gray-900 break-all">{{ $store->id }}</code>
                        </div>

                        @if ($store->account_id)
                            <div>
                                <label class="text-sm font-medium text-gray-700">ID Учетной записи</label>
                                <code class="block text-xs bg-gray-100 p-2 rounded mt-1 text-gray-900 break-all">{{ $store->account_id }}</code>
                            </div>
                        @endif

                        @if ($store->owner_id)
                            <div>
                                <label class="text-sm font-medium text-gray-700">ID Владельца</label>
                                <code class="block text-xs bg-gray-100 p-2 rounded mt-1 text-gray-900 break-all">{{ $store->owner_id }}</code>
                            </div>
                        @endif

                        <div>
                            <label class="text-sm font-medium text-gray-700">Статус архива</label>
                            <p class="mt-1">
                                @if ($store->archived)
                                    <span class="text-red-600">Да, архивирован</span>
                                @else
                                    <span class="text-green-600">Нет, активен</span>
                                @endif
                            </p>
                        </div>

                        <div>
                            <label class="text-sm font-medium text-gray-700">Общий доступ</label>
                            <p class="mt-1">
                                @if ($store->shared)
                                    <span class="text-blue-600">Да, включен</span>
                                @else
                                    <span class="text-gray-600">Нет</span>
                                @endif
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Zones and Slots Info -->
        @if ($store->attributes)
            @php
                $attributes = json_decode($store->attributes, true);
            @endphp

            @if (!empty($attributes['zones']) || !empty($attributes['slots']))
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-900 mb-6">Зоны и Ячейки</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        @if (!empty($attributes['zones']))
                            <div>
                                <h3 class="font-medium text-gray-900 mb-4">Зоны ({{ count($attributes['zones']) }})</h3>
                                <div class="space-y-2">
                                    @foreach ($attributes['zones'] as $zone)
                                        <div class="bg-gray-50 p-3 rounded">
                                            <p class="text-sm text-gray-900">{{ $zone['name'] ?? $zone['id'] ?? 'Неизвестная зона' }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if (!empty($attributes['slots']))
                            <div>
                                <h3 class="font-medium text-gray-900 mb-4">Ячейки ({{ count($attributes['slots']) }})</h3>
                                <div class="space-y-2">
                                    @foreach ($attributes['slots'] as $slot)
                                        <div class="bg-gray-50 p-3 rounded">
                                            <p class="text-sm text-gray-900">{{ $slot['name'] ?? $slot['id'] ?? 'Неизвестная ячейка' }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        @endif
    </div>
@endsection
