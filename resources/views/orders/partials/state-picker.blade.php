{{--
    Статус заявки: плашка текущего статуса, по клику — список используемых.
    Выбор сразу уходит в МойСклад.

    Параметры:
      $order  — заявка
      $states — Collection используемых статусов (OrderState::enabled())
      $size   — 'sm' для компактных мест (список заявок), иначе обычный
      $deptView — OrderDepartmentStateService::viewFor(): если все отделы смотрящего
                  поставили заявке финальный статус, кнопка показывает его, а не общий;
                  choices — мастер нескольких отделов заявки выбирает, за какие ставит статус
--}}
@php
    $states   = $states ?? collect();
    $size     = $size ?? '';
    $btnId    = 'state-picker-' . $order->id;
    $own      = ($deptView ?? null)['state'] ?? null;
    $curId    = $own?->id ?? $order->state_moysklad_id;
    $curName  = $own?->name ?? $order->state_name;
    $curColor = $own?->hex_color ?? $order->state_color;
    $curText  = $own ? \App\Support\BadgeColor::textFor($own->hex_color) : $order->state_text_color;
    $choices  = ($deptView ?? null)['choices'] ?? [];
@endphp

@if($states->isEmpty())
    {{-- Некуда переключать: справочник пуст или статусы не отмечены --}}
    <span class="badge" style="background-color: {{ $curColor }}; color: {{ $curText }}">
        {{ $curName ?? '—' }}
    </span>
@else
    <form method="POST" action="{{ route('orders.state.update', $order->uuid) }}"
          class="d-inline-block" data-submit-guard>
        @csrf
        <div class="dropdown">
            <button type="button"
                    id="{{ $btnId }}"
                    class="btn {{ $size === 'sm' ? 'btn-sm' : '' }} dropdown-toggle"
                    data-bs-toggle="dropdown"
                    data-bs-popper-config='{"strategy":"fixed"}'
                    @if($choices) data-bs-auto-close="outside" @endif
                    aria-expanded="false"
                    style="background-color: {{ $curColor }}; color: {{ $curText }}">
                {{ $curName ?? '—' }}
            </button>

            <ul class="dropdown-menu shadow-sm" aria-labelledby="{{ $btnId }}">
                @if($choices)
                    {{-- Галочки в той же форме — уходят вместе с нажатым статусом --}}
                    <li class="px-3 pt-1 pb-2" style="font-size:.8rem">
                        <div class="text-muted mb-1">За отделы:</div>
                        {{-- Маркер выбора: без него снятые все галочки читались бы как «за все отделы».
                             Отдела 0 нет — пересечение в сервисе его отбросит. --}}
                        <input type="hidden" name="departments[]" value="0">
                        @foreach($choices as $deptId => $deptName)
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="departments[]"
                                       value="{{ $deptId }}" id="{{ $btnId }}-dept-{{ $deptId }}" checked>
                                <label class="form-check-label" for="{{ $btnId }}-dept-{{ $deptId }}">{{ $deptName }}</label>
                            </div>
                        @endforeach
                    </li>
                    <li><hr class="dropdown-divider"></li>
                @endif
                @foreach($states as $state)
                    @php $isCurrent = $curId === $state->id; @endphp
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
