{{--
    Отделы заявки: бейджи, по клику — модалка выбора отделов (orders.partials.departments-modal).
    У внутреннего заказа — только бейджи исполнителя.

    Параметры:
      $order — заявка (с загруженными departments)
      $font  — размер шрифта бейджей, по умолчанию не задан
--}}
@php $font = $font ?? null; @endphp

@if($order->isInternal())
    {{-- Исполнителя внутреннего заказа меняет заказчик в форме правки: модалка отделов
         показывает только свои отделы, а исполнитель обычно чужой. --}}
    <span title="Отдел-исполнитель">
        @foreach($order->departments as $dept)
            <span class="badge bg-light text-dark border me-1"
                  @if($font) style="font-size:{{ $font }}" @endif>{{ $dept->name }}</span>
        @endforeach
    </span>
@else
<button type="button"
        class="btn btn-link p-0 text-start text-decoration-none order-departments-btn"
        data-bs-toggle="modal"
        data-bs-target="#order-departments-modal"
        data-action="{{ route('orders.departments.update', $order->uuid) }}"
        data-name="{{ $order->name }}"
        data-departments='@json($order->departments->pluck('id'))'
        title="Изменить отделы">
    @forelse($order->departments as $dept)
        <span class="badge bg-light text-dark border me-1"
              @if($font) style="font-size:{{ $font }}" @endif>{{ $dept->name }}</span>
    @empty
        <span class="text-muted small"><i class="bi bi-plus-circle"></i> отдел</span>
    @endforelse
</button>
@endif
