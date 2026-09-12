{{--
    Метки правил себестоимости, применённых к позиции (снапшот
    production_item_modifiers).

    Единственное место серверной разметки плашек: раньше «подкол 80% /
    торцовка / < 50мм» были выписаны в пяти шаблонах, и копии разошлись —
    где-то не выводилась торцовка, где-то мелкая плитка. Вид совпадает с
    badge() из resources/js/modifier-picker.js: одно и то же правило в форме
    и в отчёте должно выглядеть одинаково.

    Параметры:
      $modifiers — коллекция снапшотов, правил или массивов;
      $variant   — 'badge' (иконка + название) или 'icon' (только иконки,
                   для тесных карточек);
      $font      — размер шрифта плашки, по умолчанию .65rem;
      $class     — дополнительные классы каждой метки.

    Переменные с префиксом mb: @include делит область видимости с родителем,
    где $item, $row и $color уже заняты.
--}}
@php
    $mbVariant = $variant ?? 'badge';
    $mbFont    = $font ?? '.65rem';
    $mbClass   = $class ?? '';
@endphp
@foreach($modifiers ?? [] as $mbRule)
    @php
        $mbName  = data_get($mbRule, 'name');
        $mbColor = data_get($mbRule, 'color') ?: \App\Models\DepartmentModifier::COLOR_FALLBACK;
        $mbIcon  = data_get($mbRule, 'icon');
        $mbText  = \App\Models\DepartmentModifier::textColorFor($mbColor);
    @endphp
    @if($mbVariant === 'icon' && $mbIcon)
        <i class="bi {{ $mbIcon }} {{ $mbClass }}" style="color:{{ $mbColor }}" title="{{ $mbName }}"></i>
    @else
        {{-- Вариант icon без иконки деградирует в плашку: иначе правило станет невидимым. --}}
        <span class="badge modifier-badge {{ $mbClass }}"
              style="font-size:{{ $mbFont }};background:{{ $mbColor }};color:{{ $mbText }}">
            @if($mbIcon)<i class="bi {{ $mbIcon }} me-1"></i>@endif{{ $mbName }}
        </span>
    @endif
@endforeach
