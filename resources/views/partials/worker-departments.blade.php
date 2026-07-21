{{--
    Выбор отделов работника: чекбоксы (все отделы) + радио «основной».
    Ожидает: $departments (коллекция), $worker (Worker|null).
--}}
@php
    $selectedIds = collect(old(
        'department_ids',
        isset($worker) ? $worker->departments->pluck('id')->all() : []
    ))->map(fn($id) => (string) $id)->all();

    $primaryId = (string) old('department_id', $worker->department_id ?? '');
@endphp

<div class="mb-3">
    <label class="form-label">Отделы</label>
    <div class="border rounded p-2" style="border-radius:.4rem">
        @forelse($departments as $department)
            <div class="d-flex align-items-center justify-content-between py-1
                        @if(!$loop->last) border-bottom @endif">
                <div class="form-check mb-0">
                    <input class="form-check-input dept-check" type="checkbox"
                           name="department_ids[]" value="{{ $department->id }}"
                           id="dept-{{ $department->id }}"
                           data-department-id="{{ $department->id }}"
                           {{ in_array((string) $department->id, $selectedIds, true) ? 'checked' : '' }}>
                    <label class="form-check-label" for="dept-{{ $department->id }}">
                        {{ $department->name }}
                        @if($department->code)<span class="text-muted small">({{ $department->code }})</span>@endif
                    </label>
                </div>
                <div class="form-check mb-0">
                    <input class="form-check-input dept-primary" type="radio"
                           name="department_id" value="{{ $department->id }}"
                           id="dept-primary-{{ $department->id }}"
                           data-department-id="{{ $department->id }}"
                           {{ $primaryId === (string) $department->id ? 'checked' : '' }}>
                    <label class="form-check-label small text-muted" for="dept-primary-{{ $department->id }}">
                        основной
                    </label>
                </div>
            </div>
        @empty
            <div class="text-muted small">Нет активных отделов</div>
        @endforelse
    </div>
    <div class="form-text">
        Работник может состоять в нескольких отделах. Основной отдел подставляется по умолчанию в формах операций.
    </div>
    @error('department_id')<div class="text-danger small">{{ $message }}</div>@enderror
    @error('department_ids')<div class="text-danger small">{{ $message }}</div>@enderror
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Отметка «основной» автоматически включает членство в отделе;
        // снятие членства снимает и «основной».
        document.querySelectorAll('.dept-primary').forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (!radio.checked) return;
                const check = document.querySelector(
                    '.dept-check[data-department-id="' + radio.dataset.departmentId + '"]'
                );
                if (check) check.checked = true;
            });
        });

        document.querySelectorAll('.dept-check').forEach(function (check) {
            check.addEventListener('change', function () {
                if (check.checked) return;
                const radio = document.querySelector(
                    '.dept-primary[data-department-id="' + check.dataset.departmentId + '"]'
                );
                if (radio) radio.checked = false;
            });
        });
    });
</script>
@endpush
