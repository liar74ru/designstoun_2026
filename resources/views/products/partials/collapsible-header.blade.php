{{--
    Шапка сворачиваемого info-block (Bootstrap collapse, по умолчанию свёрнут).
    Параметры: $target — id блока с содержимым, $title, $hint — подсказка в свёрнутом виде.
--}}
<button type="button"
        class="info-block-header info-block-toggle collapsed small"
        data-bs-toggle="collapse"
        data-bs-target="#{{ $target }}"
        aria-expanded="false"
        aria-controls="{{ $target }}">
    <i class="bi bi-chevron-right text-muted"></i>
    <span class="text-nowrap">{{ $title }}</span>
    @if(!empty($hint))
        <span class="info-block-hint ms-auto text-muted fw-normal text-truncate">{{ $hint }}</span>
    @endif
</button>
