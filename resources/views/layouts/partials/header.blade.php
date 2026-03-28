<header class="fixed w-full">
    <nav class="bg-white border-gray-200 py-2.5 shadow-md">
        <div class="flex flex-wrap items-center justify-between max-w-screen-xl px-4 mx-auto">
            <!-- Логотип -->
            <a href="{{ url('/') }}" class="text-decoration-none">
                <span class="fw-bold fs-4 text-dark d-none d-sm-inline">ООО "Дизайн Камень"</span>
                <span class="fw-bold fs-4 text-dark d-sm-none">TM</span>
            </a>

            <!-- Центральное меню для десктопов — содержимое зависит от роли пользователя -->
            @auth
                @if(auth()->user()->isMaster())
                    {{-- Мастер: только Приём камня и Перемещение сырья --}}
                    <div class="d-none d-md-flex align-items-center">
                        <nav class="navbar navbar-expand-md p-0">
                            <div class="container-fluid p-0">
                                <ul class="navbar-nav me-auto mb-2 mb-md-0">
                                    <li class="nav-item mx-2">
                                        <a href="{{ route('stone-receptions.logs') }}" class="nav-link text-dark px-3 py-2 rounded">
                                            Прием камня
                                        </a>
                                    </li>
                                    <li class="nav-item mx-2">
                                        <a href="{{ route('raw-batches.index') }}" class="nav-link text-dark px-3 py-2 rounded">
                                            Перемещение сырья
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </nav>
                    </div>
                @elseif(!auth()->user()->isWorker())
                    {{-- Администратор и остальные: полное меню --}}
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
                @endif
                {{-- Рабочий (Пильщик, Галтовщик): меню не отображается --}}
            @endauth

            <!-- Правая часть: auth-кнопки -->
            <div class="d-flex align-items-center">
                <div class="d-flex align-items-center">
                    <div class="d-none d-sm-flex align-items-center me-3">
                        @auth
                            @if(auth()->user()->isWorker())
                                {{-- Рабочий (Пильщик, Галтовщик): только 3 кнопки --}}
                                <a href="{{ route('worker.dashboard') }}"
                                   class="btn btn-outline-primary btn-sm me-2">
                                    ⛏️ Моя выработка
                                </a>
                                @if(auth()->user()?->worker)
                                    <a href="{{ route('workers.edit-user', auth()->user()->worker) }}"
                                       class="btn btn-outline-secondary btn-sm me-2">
                                        <i class="bi bi-person"></i> Профиль
                                    </a>
                                @endif
                            @else
                                {{-- Администратор и другие — стандартное меню --}}
                                @if(auth()->user()->worker_id && !auth()->user()->isAdmin())
                                    <a href="{{ route('worker.dashboard') }}"
                                       class="btn btn-outline-primary btn-sm me-2">
                                        ⛏️ Моя выработка
                                    </a>
                                @endif
                                @if(auth()->user()?->worker)
                                    <a href="{{ route('workers.edit-user', auth()->user()->worker) }}" class="btn btn-primary btn-sm me-2">
                                        <i class="bi bi-key"></i> Пароль
                                    </a>
                                @endif
                            @endif

                            <a href="{{ route('logout') }}"
                               onclick="event.preventDefault(); document.getElementById('logout-form').submit();"
                               class="btn btn-outline-danger btn-sm">
                                {{ __('Logout') }}
                            </a>

                            <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                                @csrf
                            </form>
                        @else
                            <a href="{{ route('login') }}" class="btn btn-primary">
                                {{ __('Login')}}
                            </a>
                        @endauth
                    </div>
                </div>
            </div>
        </div>
    </nav>
</header>
