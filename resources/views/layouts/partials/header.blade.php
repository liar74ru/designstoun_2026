<header class="fixed w-full">
    <nav class="bg-white border-gray-200 py-2.5 shadow-md">
        <div class="flex flex-wrap items-center justify-between max-w-screen-xl px-4 mx-auto">
            <!-- Логотип -->
            <a href="{{ url('/') }}" class="text-decoration-none">
                <span class="fw-bold fs-4 text-dark d-none d-sm-inline">ООО "Дизайн Камень"</span>
                <span class="fw-bold fs-4 text-dark d-sm-none">TM</span>
            </a>

            <!-- Центральное меню для десктопов -->
            <div class="d-none d-md-flex align-items-center">
                <nav class="navbar navbar-expand-md p-0">
                    <div class="container-fluid p-0">
                        <ul class="navbar-nav me-auto mb-2 mb-md-0">
                            <li class="nav-item mx-2">
                                <a href="{{ route('products.index') }}" class="nav-link text-dark px-3 py-2 rounded">
                                    Товары
                                </a>
                            </li>
                            <li class="nav-item mx-2">
                                <a href="{{ route('orders.index') }}" class="nav-link text-dark px-3 py-2 rounded">
                                    Заказы
                                </a>
                            </li>
                            <li class="nav-item mx-2">
                                <a href="{{ route('stone-receptions.index') }}" class="nav-link text-dark px-3 py-2 rounded">
                                    Прием камня
                                </a>
                            </li>
                            <li class="nav-item mx-2">
                                <a href="{{ route('raw-batches.index') }}" class="nav-link text-dark px-3 py-2 rounded">
                                    Перемещение сырья
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link {{ request()->routeIs('workers.*') ? 'active' : '' }}"
                                   href="{{ route('workers.index') }}">
                                    👥 Работники
                                </a>
                            </li>
                        </ul>
                    </div>
                </nav>
            </div>

            <!-- Правая часть: кнопка меню + auth + языки -->
            <div class="d-flex align-items-center">

                <!-- Аутентификация и языки -->
                <div class="d-flex align-items-center">
                    <!-- Auth кнопки - скрываем на очень маленьких экранах -->
                    <div class="d-none d-sm-flex align-items-center me-3">
                        @auth
                            <a href="{{ route('profile.edit') }}" class="btn btn-primary">
                                {{ __('Profile') }}
                            </a>
                            <a href="{{ route('logout') }}"
                               onclick="event.preventDefault(); document.getElementById('logout-form').submit();"
                               class="auth-button ml-2">
                                {{ __('Logout') }}
                            </a>

                            <!-- Скрытая форма для POST запроса -->
                            <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                                @csrf
                            </form>
                        @else
                            <a href="{{ route('login') }}" class="btn btn-primary">
                                {{ __('Login')}}
                            </a>
                            <a href="{{ route('register') }}" class="btn btn-primary ml-2">
                                {{ __('Register')}}
                            </a>
                        @endauth
                    </div>
                </div>
            </div>
        </div>
    </nav>
</header>
