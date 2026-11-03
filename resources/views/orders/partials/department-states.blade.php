{{--
    Финальные статусы отделов заявки (OrderDepartmentStateService): отдел, который закончил,
    видит «Ждём: …», остальные — кто уже закончил и с каким статусом.

    Параметры:
      $deptView — OrderDepartmentStateService::viewFor() или null (отметок нет)
      $class    — доп. классы обёртки
--}}
@if(($deptView ?? null) && ($deptView['state'] || $deptView['finished']))
    <div class="d-flex flex-wrap gap-1 {{ $class ?? '' }}" style="font-size:.7rem">
        @if($deptView['state'])
            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                  title="Общий статус сменится, когда закончат все отделы заявки">
                <i class="bi bi-hourglass-split"></i> Ждём: {{ implode(', ', $deptView['waiting']) }}
            </span>
        @else
            @foreach($deptView['finished'] as $done)
                <span class="badge bg-light text-dark border" title="Отдел поставил себе статус «{{ $done['state']->name }}»">
                    <i class="bi bi-check-lg text-success"></i> {{ $done['name'] }} · {{ $done['state']->name }}
                </span>
            @endforeach
        @endif
    </div>
@endif
