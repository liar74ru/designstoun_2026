{{--
    Ссылка «приёмки за период производства» у позиции заявки — только для админа.
    Открывает список приёмок (вид «По приёмкам»: он фильтрует по дате лога, как и расчёт
    «Изготовлено») по товару позиции и дням окна производства заявки.

    Фильтр по целым дням: в дни старта и окончания окна попадут и логи вне его. Товар,
    общий с другими заявками, покажет и приёмки, ушедшие в их долю.

    Параметры: $order, $row, $class (опц.).
--}}
@can('manage-admin')
    @if($row['product'] && $order->production_started_at)
        @php
            $from = $order->production_started_at;
            $to   = $order->production_ended_at ?? now();
        @endphp
        <a href="{{ route('stone-receptions.index', [
                'view'      => 'logs',
                'date_from' => $from->format('Y-m-d'),
                'date_to'   => $to->format('Y-m-d'),
                'filter'    => ['product_id' => $row['product']->id],
            ]) }}"
           class="text-muted text-decoration-none {{ $class ?? '' }}"
           title="Все приёмки товара за период производства: {{ $from->format('d.m') }}–{{ $to->format('d.m') }}">
            <i class="bi bi-box-arrow-up-right"></i>
        </a>
    @endif
@endcan
