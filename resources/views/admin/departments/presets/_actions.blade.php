{{--
    Кнопки пресета во вкладке «Пресеты» карточки отдела: изменить, копировать в отдел,
    удалить (в основном отделе — из всех отделов) или убрать из отдела (в подключённом).
    Параметры: $preset, $department, $allDepartments.
--}}
<div class="d-flex align-items-center gap-1 flex-shrink-0">
    <a href="{{ route('admin.departments.presets.edit', [$department, $preset]) }}"
       class="btn btn-sm btn-outline-primary" title="Изменить">
        <i class="bi bi-pencil"></i>
    </a>
    <div class="dropdown">
        <button type="button" class="btn btn-sm btn-outline-secondary" title="Копировать в другой отдел"
                data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
            <i class="bi bi-copy"></i>
        </button>
        <div class="dropdown-menu dropdown-menu-end p-2 shadow" style="min-width:240px">
            <form method="POST" data-submit-guard action="{{ route('admin.departments.presets.copy', [$department, $preset]) }}">
                @csrf
                <label class="form-label small text-muted mb-1">Копировать в отдел</label>
                <select name="target_department_id" class="form-select form-select-sm mb-2" style="border-radius:.4rem">
                    @foreach($allDepartments as $dept)
                        <option value="{{ $dept->id }}" @selected($dept->id === $department->id)>{{ $dept->name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-sm btn-primary w-100">
                    <i class="bi bi-copy"></i> Копировать
                </button>
            </form>
        </div>
    </div>
    @if($preset->department_id === $department->id)
        <form method="POST" data-submit-guard action="{{ route('admin.departments.presets.destroy', [$department, $preset]) }}"
              data-confirm="Удалить пресет «{{ $preset->name }}»?{{ $preset->departments->count() > 1 ? ' Он пропадёт во всех отделах: ' . $preset->departments->pluck('name')->implode(', ') . '.' : '' }}">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-sm btn-outline-danger" title="Удалить">
                <i class="bi bi-trash"></i>
            </button>
        </form>
    @else
        <form method="POST" data-submit-guard action="{{ route('admin.departments.presets.detach', [$department, $preset]) }}"
              data-confirm="Убрать пресет «{{ $preset->name }}» из этого отдела? В отделе «{{ $preset->department?->name }}» он останется.">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-danger" title="Убрать из отдела">
                <i class="bi bi-box-arrow-right"></i>
            </button>
        </form>
    @endif
</div>
