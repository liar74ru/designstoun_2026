{{--
    Статус заявки: плашка текущего статуса, по клику — список используемых.
    Выбор сразу уходит в МойСклад.

    Параметры:
      $order  — заявка
      $states — Collection используемых статусов (OrderState::enabled())
      $size   — 'sm' для компактных мест (список заявок), иначе обычный
--}}
@php
    $states = $states ?? collect();
    $size   = $size ?? '';
    $btnId  = 'state-picker-' . $order->id;
@endphp

@if($states->isEmpty())
    {{-- Некуда переключать: справочник пуст или статусы не отмечены --}}
    <span class="badge" style="background-color: {{ $order->state_color }}; color: {{ $order->state_text_color }}">
        {{ $order->state_name ?? '—' }}
    </span>
@else
    <form method="POST" action="{{ route('orders.state.update', $order->moysklad_id) }}"
          class="d-inline-block" data-submit-guard>
        @csrf
        <div class="dropdown">
            <button type="button"
                    id="{{ $btnId }}"
                    class="btn {{ $size === 'sm' ? 'btn-sm' : '' }} dropdown-toggle"
                    data-bs-toggle="dropdown"
                    aria-expanded="false"
                    style="background-color: {{ $order->state_color }}; color: {{ $order->state_text_color }}">
                {{ $order->state_name ?? '—' }}
            </button>

            <ul class="dropdown-menu shadow-sm" aria-labelledby="{{ $btnId }}">
                @foreach($states as $state)
                    @php $isCurrent = $order->state_moysklad_id === $state->id; @endphp
                    <li>
                        <button type="submit" name="state_id" value="{{ $state->id }}"
                                class="dropdown-item d-flex align-items-center gap-2 {{ $isCurrent ? 'disabled' : '' }}"
                                @disabled($isCurrent)>
                            <span class="badge flex-shrink-0"
                                  style="background-color: {{ $state->hex_color }}; color: {{ \App\Support\BadgeColor::textFor($state->hex_color) }}">
                                {{ $state->name }}
                            </span>
                            @if($isCurrent)
                                <i class="bi bi-check-lg text-success ms-auto"></i>
                            @endif
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
    </form>
@endif
