{{--
    Общая форма группы пресетов цеха (create/edit).
    Параметры: $action, $method ('POST'|'PATCH'), $department, $group (null при создании).
--}}
@php
    $icon = old('icon', $group?->icon);
@endphp

@push('styles')
    <style>
        /* Набор иконок: выбор целиком на CSS, как в форме правила отдела. */
        .group-icon{display:inline-block;line-height:0}
        .group-icon__box{
            display:inline-flex;align-items:center;justify-content:center;
            flex:0 0 auto;width:36px;height:36px;border-radius:.4rem;
            border:1px solid rgba(0,0,0,.15);cursor:pointer;font-size:1.05rem;
        }
        .group-icon input:checked + .group-icon__box{
            box-shadow:0 0 0 3px rgba(13,110,253,.4);border-color:#0D6EFD;
        }
        .group-icon input:focus-visible + .group-icon__box{outline:2px solid #0D6EFD;outline-offset:2px}
    </style>
@endpush

<form method="POST" action="{{ $action }}" data-submit-guard>
    @csrf
    @if($method !== 'POST')
        @method($method)
    @endif

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="mb-3">
                <label for="groupName" class="form-label fw-semibold">
                    Название <span class="text-danger">*</span>
                </label>
                <input type="text" id="groupName" name="name"
                       value="{{ old('name', $group?->name) }}"
                       class="form-control @error('name') is-invalid @enderror"
                       style="border-radius:.4rem"
                       maxlength="100" required>
                @error('name')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-1">
                <label class="form-label fw-semibold">Иконка</label>
                <div class="d-flex flex-wrap gap-2">
                    @foreach(\App\Models\WorkshopPresetGroup::ICONS as $class => $label)
                        <label class="group-icon" title="{{ $label }}">
                            <input type="radio" name="icon" value="{{ $class }}" class="visually-hidden"
                                   @checked($icon === $class)>
                            <span class="group-icon__box"><i class="bi {{ $class }}"></i></span>
                        </label>
                    @endforeach
                    <label class="group-icon" title="Без иконки">
                        <input type="radio" name="icon" value="" class="visually-hidden"
                               @checked($icon === null || $icon === '')>
                        <span class="group-icon__box text-muted"><i class="bi bi-slash-circle"></i></span>
                    </label>
                </div>
                @error('icon')
                    <div class="text-danger small mt-1">{{ $message }}</div>
                @enderror
                <div class="form-text" style="font-size:.72rem">
                    Иконка показывается рядом с названием группы в блоке «Шаблоны» формы цеха.
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary px-4">
            <i class="bi bi-check-lg"></i> Сохранить
        </button>
        <a href="{{ route('admin.departments.show', $department) }}#workshop" class="btn btn-outline-secondary" data-back>
            Отмена
        </a>
    </div>
</form>
