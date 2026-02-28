@extends('layouts.app')

@section('title', 'Task Manager')

@section('content')
    <div class="container py-5">
        <!-- Заголовок страницы -->
        <h1 class="text-center mb-5 display-4 fw-bold">
            Панель управления
        </h1>

        <!-- Сетка с плитками -->
        <div class="row g-4">
            <!-- Плитка Товары -->
            <div class="col-12 col-sm-6 col-md-4 col-lg-3">
                <a href="{{ route('products.index') }}" class="text-decoration-none">
                    <div class="card h-100 shadow-sm hover-shadow transition">
                        <div class="card-body text-center p-4">
                            <div class="display-1 mb-3">📦</div>
                            <h5 class="card-title fw-bold">Товары</h5>
                            <p class="card-text text-muted small">Управление товарами</p>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Плитка Заказы -->
            <div class="col-12 col-sm-6 col-md-4 col-lg-3">
                <a href="#" class="text-decoration-none">
                    <div class="card h-100 shadow-sm hover-shadow transition">
                        <div class="card-body text-center p-4">
                            <div class="display-1 mb-3">📋</div>
                            <h5 class="card-title fw-bold">Заказы</h5>
                            <p class="card-text text-muted small">Управление заказами</p>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Плитка Приемка плитки -->
            <div class="col-12 col-sm-6 col-md-4 col-lg-3">
                <a href="#" class="text-decoration-none">
                    <div class="card h-100 shadow-sm hover-shadow transition">
                        <div class="card-body text-center p-4">
                            <div class="display-1 mb-3">📥</div>
                            <h5 class="card-title fw-bold">Приемка плитки</h5>
                            <p class="card-text text-muted small">Приемка и контроль</p>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Плитка Склад -->
            <div class="col-12 col-sm-6 col-md-4 col-lg-3">
                <a href="{{ route('stores.index') }}" class="text-decoration-none">
                    <div class="card h-100 shadow-sm hover-shadow transition">
                        <div class="card-body text-center p-4">
                            <div class="display-1 mb-3">🏭</div>
                            <h5 class="card-title fw-bold">Склад</h5>
                            <p class="card-text text-muted small">Учет на складе</p>
                        </div>
                    </div>
                </a>
            </div>
        </div>

        <div class="row g-4 mt-2">
            <div class="col-12 col-sm-6 col-md-4 col-lg-3">
                <a href=" {{ route('workers.index') }}" class="text-decoration-none">
                    <div class="card h-100 shadow-sm hover-shadow transition">
                        <div class="card-body text-center p-4">
                            <div class="display-1 mb-3">👥</div>
                            <h5 class="card-title fw-bold">Работники</h5>
                            <p class="card-text text-muted small">Управление персоналом</p>
                        </div>
                    </div>
                </a>
            </div>
        </div>
    </div>

        <!-- Пример для добавления новых плиток (закомментирован) -->
        {{--
        <div class="row g-4 mt-2">
            <div class="col-12 col-sm-6 col-md-4 col-lg-3">
                <a href="#" class="text-decoration-none">
                    <div class="card h-100 shadow-sm hover-shadow transition">
                        <div class="card-body text-center p-4">
                            <div class="display-1 mb-3">➕</div>
                            <h5 class="card-title fw-bold">Новый раздел</h5>
                            <p class="card-text text-muted small">Описание раздела</p>
                        </div>
                    </div>
                </a>
            </div>
        </div>
        --}}
    </div>
@endsection
