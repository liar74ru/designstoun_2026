{{--
    Кнопка «скрыть позицию для отдела» в карточке заявки. Скрытая позиция пропадает
    из списка заявок у этого отдела; в карточке она остаётся.
    Параметры: $order, $row, $hideDepartments — отделы заявки, которыми управляет пользователь.
    Стили и скрипт — orders.partials.hide-toggle-assets.
--}}
@if($row['product'] && $hideDepartments->isNotEmpty())
    <div class="dropdown hide-toggle flex-shrink-0"
         data-url="{{ route('orders.position.hidden', [$order->moysklad_id, $row['product']->id]) }}"
         data-key="{{ $order->id }}-{{ $row['product']->id }}"
         data-controlled='@json($hideDepartments->pluck('id')->values())'
         data-names='@json($order->departments->sortBy('name')->map(fn ($d) => [$d->id, $d->name])->values())'>
        <button type="button" class="btn btn-link btn-sm p-0 text-muted hide-toggle-btn"
                data-bs-toggle="dropdown" aria-expanded="false"
                data-bs-popper-config='{"strategy":"fixed"}'
                title="Скрыть позицию для отдела">
            <i class="bi bi-eye-slash"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="font-size:.8rem">
            <li><h6 class="dropdown-header">Скрыть в списке заявок для</h6></li>
            @foreach($hideDepartments as $dept)
                @php $on = in_array($dept->id, $row['hiddenFor'], true); @endphp
                <li>
                    <button type="button" class="dropdown-item hide-toggle-item"
                            data-department-id="{{ $dept->id }}" data-hidden="{{ $on ? 1 : 0 }}">
                        <i class="bi bi-check-lg me-1 {{ $on ? '' : 'invisible' }}"></i>{{ $dept->name }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
@endif
