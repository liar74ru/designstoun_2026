{{--
    Быстрый переключатель отделов — дубль фильтра filter[department_id][].
    Плитки без горизонтальной прокрутки: до 5 — в один ряд, больше — поровну в два ряда;
    на телефоне — не больше трёх в ряд.
    Показывается только если доступных отделов больше одного.
    Параметры:
      $departments — Collection отделов, доступных пользователю
      $routeName   — string: имя маршрута index-страницы
--}}
@php
    $departments = $departments ?? collect();
    $selected    = array_map('intval', (array) request('filter.department_id', []));
    $base        = request()->except(['page', 'filter.department_id']);
    $deptUrl     = function (?int $id) use ($base, $routeName) {
        if ($id !== null) {
            $base['filter'] = array_merge($base['filter'] ?? [], ['department_id' => [$id]]);
        }

        return route($routeName, $base);
    };
@endphp

@if($departments->count() > 1)
    @php
        $tiles      = $departments->count() + 1; // «Все» + отделы
        $tileCols   = $tiles <= 5 ? $tiles : (int) ceil($tiles / 2);
        $tileColsSm = $tiles === 4 ? 2 : min($tiles, 3);
    @endphp
    <div class="mb-3 department-switcher" role="group" aria-label="Отдел"
         style="--dept-cols: {{ $tileCols }}; --dept-cols-sm: {{ $tileColsSm }}">
        {{-- data-filter-reset — чтобы filter-memory.js не вернул сохранённый отдел обратно --}}
        <a href="{{ $deptUrl(null) }}" data-filter-reset
           class="btn btn-sm {{ empty($selected) ? 'btn-primary' : 'btn-outline-primary' }}">
            Все
        </a>
        @foreach($departments as $dept)
            <a href="{{ $deptUrl($dept->id) }}" title="{{ $dept->name }}"
               class="btn btn-sm {{ $selected === [$dept->id] ? 'btn-primary' : 'btn-outline-primary' }}">
                {{ $dept->name }}
            </a>
        @endforeach
    </div>
@endif

@once
    @push('styles')
        <style>
            .department-switcher {
                display: grid;
                grid-template-columns: repeat(var(--dept-cols), minmax(0, 1fr));
                gap: .25rem;
            }
            .department-switcher .btn {
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            @media (max-width: 767.98px) {
                .department-switcher { grid-template-columns: repeat(var(--dept-cols-sm), minmax(0, 1fr)); }
            }
        </style>
    @endpush
@endonce
