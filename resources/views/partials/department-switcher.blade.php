{{--
    Быстрый переключатель отделов — дубль фильтра filter[department_id][].
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
    <div class="btn-group btn-group-sm w-100 mb-3 department-switcher" role="group" aria-label="Отдел">
        {{-- data-filter-reset — чтобы filter-memory.js не вернул сохранённый отдел обратно --}}
        <a href="{{ $deptUrl(null) }}" data-filter-reset
           class="btn {{ empty($selected) ? 'btn-primary' : 'btn-outline-primary' }}">
            Все
        </a>
        @foreach($departments as $dept)
            <a href="{{ $deptUrl($dept->id) }}"
               class="btn {{ $selected === [$dept->id] ? 'btn-primary' : 'btn-outline-primary' }}">
                {{ $dept->name }}
            </a>
        @endforeach
    </div>
@endif

@once
    @push('styles')
        <style>
            .department-switcher { overflow-x: auto; }
            .department-switcher .btn { white-space: nowrap; }
        </style>
    @endpush
@endonce
