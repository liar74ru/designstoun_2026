<header class="fixed w-full" style="z-index:1030">
    <nav class="bg-white border-gray-200 shadow-md">
        <div class="max-w-screen-xl px-3 mx-auto">

            {{-- Строка 1: логотип + auth-иконки --}}
            <div class="flex items-center justify-between py-2">
                {{-- Логотип: на мобильном иконка дома, на десктопе название --}}
                <a href="{{ url('/') }}" class="text-decoration-none">
                    <span class="fw-bold fs-5 text-dark d-none d-sm-inline">ООО "Дизайн Камень"</span>
                    <span class="text-dark d-sm-none fs-4"><i class="bi bi-house-door-fill"></i></span>
                </a>

                {{-- Десктоп: центральное меню --}}
                @auth
                    @if(auth()->user()->isMaster())
                        <div class="d-none d-md-flex align-items-center">
                            <ul class="navbar-nav flex-row mb-0">
                                <li class="nav-item mx-2">
                                    <a href="{{ route('stone-receptions.logs') }}" class="nav-link text-dark px-3 py-2 rounded {{ request()->routeIs('stone-receptions.*') ? 'fw-semibold' : '' }}">
                                        Прием камня
                                    </a>
                                </li>
                                <li class="nav-item mx-2">
                                    <a href="{{ route('raw-batches.index') }}" class="nav-link text-dark px-3 py-2 rounded {{ request()->routeIs('raw-batches.*') ? 'fw-semibold' : '' }}">
                                        Перемещение сырья
                                    </a>
                                </li>
                            </ul>
                        </div>
                    @elseif(!auth()->user()->isWorker())
                        <div class="d-none d-md-flex align-items-center">
                            <ul class="navbar-nav flex-row mb-0">
                                <li class="nav-item mx-2">
                                    <a href="{{ route('products.index') }}" class="nav-link text-dark px-3 py-2 rounded">Товары</a>
                                </li>
                                <li class="nav-item mx-2">
                                    <a href="{{ route('orders.index') }}" class="nav-link text-dark px-3 py-2 rounded">Заказы</a>
                                </li>
                                <li class="nav-item mx-2">
                                    <a href="{{ route('stone-receptions.index') }}" class="nav-link text-dark px-3 py-2 rounded">Прием камня</a>
                                </li>
                                <li class="nav-item mx-2">
                                    <a href="{{ route('raw-batches.index') }}" class="nav-link text-dark px-3 py-2 rounded">Перемещение сырья</a>
                                </li>
                                <li class="nav-item mx-2">
                                    <a href="{{ route('workers.index') }}" class="nav-link text-dark px-3 py-2 rounded {{ request()->routeIs('workers.*') ? 'fw-semibold' : '' }}">👥 Работники</a>
                                </li>
                            </ul>
                        </div>
                    @endif
                @endauth

                {{-- Auth-иконки: всегда справа --}}
                <div class="d-flex align-items-center gap-1">
                    @auth
                        @if(auth()->user()->isWorker())
                            <a href="{{ route('worker.dashboard') }}" class="btn btn-outline-primary btn-sm" title="Моя выработка">
                                <span class="d-none d-sm-inline">⛏️ Моя выработка</span>
                                <span class="d-sm-none">⛏️</span>
                            </a>
                            @if(auth()->user()?->worker)
                                <a href="{{ route('workers.edit-user', auth()->user()->worker) }}" class="btn btn-outline-secondary btn-sm" title="Профиль">
                                    <i class="bi bi-person"></i>
                                </a>
                            @endif
                        @else
                            @if(auth()->user()->worker_id && !auth()->user()->isAdmin())
                                <a href="{{ route('worker.dashboard') }}" class="btn btn-outline-primary btn-sm" title="Моя выработка">
                                    <span class="d-none d-sm-inline">⛏️ Моя выработка</span>
                                    <span class="d-sm-none">⛏️</span>
                                </a>
                            @endif
                            @if(auth()->user()?->worker)
                                <a href="{{ route('workers.edit-user', auth()->user()->worker) }}" class="btn btn-primary btn-sm" title="Пароль">
                                    <i class="bi bi-key"></i>
                                </a>
                            @endif
                        @endif

                        <a href="{{ route('logout') }}"
                           onclick="event.preventDefault(); document.getElementById('logout-form').submit();"
                           class="btn btn-outline-danger btn-sm" title="Выход">
                            <i class="bi bi-box-arrow-right"></i>
                        </a>

                        <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                            @csrf
                        </form>
                    @else
                        <a href="{{ route('login') }}" class="btn btn-primary btn-sm">{{ __('Login') }}</a>
                    @endauth
                </div>
            </div>

            {{-- Строка 2 (только мобильный, только мастер): кнопки навигации --}}
            @auth
                @if(auth()->user()->isMaster())
                    <div class="d-flex d-md-none justify-content-center gap-2 pb-2">
                        <a href="{{ route('stone-receptions.logs') }}"
                           class="btn btn-sm {{ request()->routeIs('stone-receptions.*') ? 'btn-primary' : 'btn-outline-secondary' }}">
                            <i class="bi bi-journal-text"></i> Приёмка
                        </a>
                        <a href="{{ route('raw-batches.index') }}"
                           class="btn btn-sm {{ request()->routeIs('raw-batches.*') ? 'btn-primary' : 'btn-outline-secondary' }}">
                            <i class="bi bi-box-seam"></i> Сырьё
                        </a>
                    </div>
                @endif
            @endauth

        </div>
    </nav>
</header>
