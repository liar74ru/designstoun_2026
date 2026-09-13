{{--
    Иконка позиции в компактной плитке: иконки ручных правил вместо иконки
    камня, как было до перехода на правила отдела — признак читается с одного
    взгляда. Правил несколько — иконки идут подряд. Автоматические правила
    (по маске SKU) здесь не показываются: они стоят на каждой плитке маски и
    засоряют строку; в таблицах позиций они по-прежнему видны.

    Параметры: $modifiers (снапшот позиции), $sku (SKU товара),
    $iconStyle (инлайн-стиль иконки камня).
--}}
@php $iiManual = collect($modifiers ?? [])->filter->isManual(); @endphp
@if($iiManual->isNotEmpty())
    @include('partials.modifier-badges', [
        'modifiers' => $iiManual,
        'variant'   => 'icon',
        'class'     => 'me-1',
    ])
@else
    <ion-icon name="{{ \App\Models\Product::getIconBySku($sku) }}" class="text-secondary me-1"
              @if(!empty($iconStyle)) style="{{ $iconStyle }}" @endif></ion-icon>
@endif
