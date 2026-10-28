<style>
.nav-icon-btn {
    display:flex;flex-direction:column;align-items:center;justify-content:center;
    width:52px;padding:.18rem .3rem;font-size:.68rem;line-height:1.15;gap:1px;
    flex-shrink:0;overflow:hidden;
}
.nav-icon-btn i { font-size:1.15rem; }
.nav-icon-btn span { white-space:nowrap; }

/* Цвет группы плитки (group в config/department_operations.php): подложка и иконка,
   активная плитка залита цветом группы. Без группы — серый через фолбэк var():
   значение по умолчанию в .nav-tile перебило бы .nav-gN более высокой специфичностью. */
.nav-g1 { --nav-g: #6741d9; }
.nav-g2 { --nav-g: #d9480f; }
.nav-g3 { --nav-g: #0c8599; }
.nav-g4 { --nav-g: #2b8a3e; }
.nav-g5 { --nav-g: #495057; }
.nav-icon-btn.nav-tile {
    color: #6c757d;
    border-color: #adb5bd;
    background-color: color-mix(in srgb, var(--nav-g, #6c757d) 9%, #fff);
}
.nav-icon-btn.nav-tile i { color: var(--nav-g, #6c757d); }
.nav-icon-btn.nav-tile:hover,
.nav-icon-btn.nav-tile:focus-visible {
    color: #495057;
    border-color: #adb5bd;
    background-color: color-mix(in srgb, var(--nav-g, #6c757d) 17%, #fff);
}
.nav-icon-btn.nav-tile.active,
.nav-icon-btn.nav-tile.active i {
    color: #fff;
}
.nav-icon-btn.nav-tile.active {
    background-color: var(--nav-g, #6c757d);
    border-color: var(--nav-g, #6c757d);
}

/* Справка и профиль друг под другом: занимают ширину одной узкой кнопки */
.nav-aux { display:flex;flex-direction:column;gap:3px; }
.nav-aux .btn:not(.btn-primary) { width:30px;height:21px;padding:0;display:flex;align-items:center;justify-content:center;font-size:.8rem; }

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
                <div class="d-flex align-items-center gap-1 flex-wrap" style="max-width:calc(100vw - 70px)">
                    <a href="{{ url('/') }}" class="text-decoration-none d-none d-sm-inline me-2">
                        <span class="fw-bold fs-5 text-dark">ООО "Дизайн Камень"</span>
                    </a>

                    @auth
                        @php $__isAdmin = auth()->user()->isAdmin(); @endphp
                        @foreach(($operationsRegistry ?? []) as $key => $op)
                            @can("see-{$key}")
                                @if($__isAdmin && !empty($op['hide_for_admin']))
                                    @continue
                                @endif
                                @php
                                    $href = !empty($op['route']) ? route($op['route']) : url($op['url'] ?? '/');
                                    $isActive = !empty($op['route_pattern'])
                                        ? request()->routeIs($op['route_pattern'])
                                        : (($op['url'] ?? null) === '/' && request()->is('/'));
                                @endphp
                                <a href="{{ $href }}"
                                   class="btn btn-sm nav-icon-btn nav-tile nav-g{{ $op['group'] ?? 0 }} {{ $isActive ? 'active' : '' }}"
                                   title="{{ $op['label'] }}">
                                    <i class="bi {{ $op['icon'] }}"></i>
                                    <span>{{ $op['short_label'] ?? $op['label'] }}</span>
                                </a>
                            @endcan
                        @endforeach
                    @endauth
                </div>

                {{-- Правая часть: справка и профиль друг под другом --}}
                <div class="nav-aux">
                    @auth
                        @php $user = auth()->user(); @endphp

                        <a href="{{ route('help.index') }}"
                           class="btn btn-sm btn-outline-secondary {{ request()->routeIs('help.*') ? 'active' : '' }}"
                           title="Справка">
                            <i class="bi bi-question-circle"></i>
                        </a>

                        @if($user->worker)
                            <a href="{{ route('workers.edit-user', $user->worker) }}"
                               class="btn btn-sm btn-outline-secondary"
                               title="Профиль">
                                <i class="bi bi-person-circle"></i>
                            </a>
                        @else
                            <a href="{{ route('logout') }}"
                               onclick="event.preventDefault(); document.getElementById('logout-form').submit();"
                               class="btn btn-outline-danger btn-sm" title="Выход">
                                <i class="bi bi-box-arrow-right"></i>
                            </a>
                        @endif

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
