<style>
.nav-icon-btn {
    display:flex;flex-direction:column;align-items:center;justify-content:center;
    width:52px;padding:.18rem .3rem;font-size:.68rem;line-height:1.15;gap:1px;
    flex-shrink:0;overflow:hidden;
}
.nav-icon-btn i { font-size:1.15rem; }
.nav-icon-btn span { white-space:nowrap; }

@media (max-width: 575px) {
    .nav-icon-btn {
        width:44px;padding:.12rem .2rem;font-size:.6rem;
    }
    .nav-icon-btn i { font-size:1rem; }
}
</style>
<header class="fixed w-full" style="z-index:1030">
    <nav class="bg-white border-gray-200 shadow-md">
        <div class="max-w-screen-xl px-3 mx-auto">

            <div class="flex items-center justify-between py-2">

                {{-- Левая часть: логотип + навигация --}}
                <div class="d-flex align-items-center gap-1 flex-wrap" style="max-width:calc(100vw - 90px)">
                    <a href="{{ url('/') }}" class="text-decoration-none d-none d-sm-inline me-2">
                        <span class="fw-bold fs-5 text-dark">ООО "Дизайн Камень"</span>
                    </a>

                    @auth
                        @php $user = auth()->user(); @endphp

                        {{-- Выработка: работники + не-мастера с привязанным worker --}}
                        @if($user->isWorker() || ($user->worker_id && !$user->isAdmin() && !$user->isMaster()))
                            <a href="{{ route('worker.dashboard') }}"
                               class="btn btn-sm nav-icon-btn {{ request()->routeIs('worker.dashboard') ? 'btn-primary' : 'btn-outline-secondary' }}"
                               title="Моя выработка">
                                <i class="bi bi-bar-chart-line"></i>
                                <span>Выраб.</span>
                            </a>
                        @endif

                        @if(!$user->isWorker())

                            {{-- Дашборд мастера --}}
                            @if($user->isMaster())
                                <a href="{{ route('master.dashboard') }}"
                                   class="btn btn-sm nav-icon-btn {{ request()->routeIs('master.dashboard') ? 'btn-primary' : 'btn-outline-secondary' }}"
                                   title="Дашборд мастера">
                                    <i class="bi bi-bar-chart-line"></i>
                                    <span>Дашборд</span>
                                </a>
                            @endif

                            {{-- Домой: только admin --}}
                            @if($user->isAdmin())
                                <a href="{{ url('/') }}"
                                   class="btn btn-sm nav-icon-btn {{ request()->is('/') ? 'btn-primary' : 'btn-outline-secondary' }}"
                                   title="Главная">
                                    <i class="bi bi-house-door-fill"></i>
                                    <span>Домой</span>
                                </a>
                            @endif

                            {{-- Приёмка: admin + master --}}
                            <a href="{{ route('stone-receptions.logs') }}"
                               class="btn btn-sm nav-icon-btn {{ request()->routeIs('stone-receptions.*') ? 'btn-primary' : 'btn-outline-secondary' }}"
                               title="Приёмка">
                                <i class="bi bi-journal-text"></i>
                                <span>Приём</span>
                            </a>

                            {{-- Сырьё (партии): admin + master --}}
                            <a href="{{ route('raw-batches.index') }}"
                               class="btn btn-sm nav-icon-btn {{ request()->routeIs('raw-batches.*') ? 'btn-primary' : 'btn-outline-secondary' }}"
                               title="Сырьё">
                                <i class="bi bi-arrow-left-right"></i>
                                <span>Сырьё</span>
                            </a>

                            {{-- Поступление сырья: admin + master --}}
                            <a href="{{ route('supplier-orders.index') }}"
                               class="btn btn-sm nav-icon-btn {{ request()->routeIs('supplier-orders.*') ? 'btn-primary' : 'btn-outline-secondary' }}"
                               title="Поступление">
                                <i class="bi bi-plus-circle"></i>
                                <span>Приход</span>
                            </a>

                            {{-- Товары: только admin --}}
                            @if($user->isAdmin())
                                <a href="{{ route('products.index') }}"
                                   class="btn btn-sm nav-icon-btn {{ request()->routeIs('products.*') ? 'btn-primary' : 'btn-outline-secondary' }}"
                                   title="Товары">
                                    <i class="bi bi-box-seam"></i>
                                    <span>Товары</span>
                                </a>
                            @endif

                            {{-- Работники: admin + master --}}
                            <a href="{{ route('workers.index') }}"
                               class="btn btn-sm nav-icon-btn {{ request()->routeIs('workers.*') ? 'btn-primary' : 'btn-outline-secondary' }}"
                               title="Работники">
                                <i class="bi bi-people"></i>
                                <span>Раб-ки</span>
                            </a>

                            {{-- Заказы: только admin --}}
                            @if($user->isAdmin())
                                <a href="{{ route('orders.index') }}"
                                   class="btn btn-sm nav-icon-btn {{ request()->routeIs('orders.*') ? 'btn-primary' : 'btn-outline-secondary' }}"
                                   title="Заказы">
                                    <i class="bi bi-bag"></i>
                                    <span>Заказы</span>
                                </a>
                            @endif

                            {{-- Настройки: только admin --}}
                            @if($user->isAdmin())
                                <a href="{{ route('admin.settings.index') }}"
                                   class="btn btn-sm nav-icon-btn {{ request()->routeIs('admin.settings.*') ? 'btn-primary' : 'btn-outline-secondary' }}"
                                   title="Настройки">
                                    <i class="bi bi-gear"></i>
                                    <span>Настройки</span>
                                </a>
                            @endif

                        @endif
                    @endauth
                </div>

                {{-- Правая часть: auth-иконки --}}
                <div class="d-flex align-items-center gap-1">
                    @auth
                        @php $user = auth()->user(); @endphp

                        {{-- Смена пароля / профиль --}}
                        @if($user->worker)
                            <a href="{{ route('workers.edit-user', $user->worker) }}"
                               class="btn btn-sm {{ $user->isAdmin() ? 'btn-primary' : 'btn-outline-secondary' }}"
                               title="{{ $user->isWorker() ? 'Профиль' : 'Пароль' }}">
                                <i class="bi bi-{{ $user->isWorker() ? 'person' : 'key' }}"></i>
                            </a>
                        @endif

                        {{-- Выход --}}
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
