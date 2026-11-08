{{--
    Запись вертикальной шкалы времени: время слева, точка на оси, карточка в слоте.
    $color — цвет точки (статус записи), например 'var(--bs-success)'.
--}}
@props(['time', 'color' => 'var(--bs-secondary)'])
<div class="d-flex">
    <div class="text-end pe-2 pt-1 fw-semibold text-secondary flex-shrink-0" style="width:52px;font-size:.8rem">
        {{ $time->format('H:i') }}
    </div>
    <div class="position-relative flex-shrink-0" style="width:22px">
        <div class="position-absolute top-0 bottom-0 start-50 translate-middle-x" style="width:2px;background:#dee2e6"></div>
        <div class="position-absolute start-50 translate-middle-x rounded-circle"
             style="top:.45rem;width:12px;height:12px;background:{{ $color }};border:2px solid #fff;box-shadow:0 0 0 1px #adb5bd"></div>
    </div>
    <div class="flex-grow-1 pb-2 ps-1" style="min-width:0;max-width:760px">
        {{ $slot }}
    </div>
</div>
