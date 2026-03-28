<header class="fixed w-full" style="z-index:1030">
    <nav class="bg-white border-gray-200 shadow-md">
        <div class="max-w-screen-xl px-3 mx-auto">

            <div class="flex items-center justify-between py-2">

                {{-- Левая часть: логотип + навигация мастера --}}
                <div class="d-flex align-items-center gap-1">
                    <a href="{{ url('/') }}" class="text-decoration-none d-none d-sm-inline me-2">
                        <span class="fw-bold fs-5 text-dark">ООО "Дизайн Камень"</span>
                    </a>

                    @auth
                        @if(auth()->user()->isMaster())
                            <a href="{{ route('stone-receptions.logs') }}"
                               class="btn btn-sm {{ request()->routeIs('stone-receptions.*') ? 'btn-primary' : 'btn-outline-secondary' }}"
                               title="Приёмка">
                                <i class="bi bi-journal-text"></i>
                                <span class="ms-1">Приёмка</span>
                            </a>
                            <a href="{{ route('raw-batches.index') }}"
                               class="btn btn-sm {{ request()->routeIs('raw-batches.*') ? 'btn-primary' : 'btn-outline-secondary' }}"
                               title="Сырьё">
                                <i class="bi bi-box-seam"></i>
                                <span class="ms-1">Сырьё</span>
                            </a>
                        @elseif(!auth()->user()->isWorker())
                            {{-- Десктоп: центральное меню для остальных ролей --}}
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
                </div>

                {{-- Правая часть: auth-иконки --}}
                <div class="d-flex align-items-center gap-1">
                    @auth
                        @if(auth()->user()->isMaster())
                            @if(auth()->user()?->worker)
                                <a href="{{ route('workers.edit-user', auth()->user()->worker) }}"
                                   class="btn btn-outline-secondary btn-sm" title="Пароль">
                                    <i class="bi bi-key"></i>
                                </a>
                            @endif
                        @elseif(auth()->user()->isWorker())
                            <a href="{{ route('worker.dashboard') }}" class="btn btn-outline-primary btn-sm" title="Моя выработка">
                                <span class="d-none d-sm-inline">⛏️ Моя выработка</span>
                                <span class="d-sm-none">⛏️</span>
                            </a>
                            @if(auth()->user()?->worker)
                                <a href="{{ route('workers.edit-user', auth()->user()->worker) }}"
                                   class="btn btn-outline-secondary btn-sm" title="Профиль">
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
                                <a href="{{ route('workers.edit-user', auth()->user()->worker) }}"
                                   class="btn btn-primary btn-sm" title="Пароль">
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

        </div>
    </nav>
</header>
