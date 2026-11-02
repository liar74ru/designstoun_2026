{{--
    Список пресетов во вкладке «Пресеты» карточки отдела: слева группы (фильтр), справа карточки.
    Параметры: $presets, $presetGroups, $presetsByGroup, $ungrouped, $presetMeta, $department, $allDepartments.
--}}
<div class="row g-3 mb-3" data-preset-filterable>
    <div class="col-md-4 col-lg-3">
        <div class="card shadow-sm">
            <div class="card-header py-2 small fw-semibold">Группы</div>
            <div class="list-group list-group-flush small">
                <div class="list-group-item pl-split-item active" data-filter-wrap>
                    <button type="button" class="pl-split-btn" data-filter-key="all">
                        <i class="bi bi-collection"></i> Все пресеты
                    </button>
                    <span class="pl-count">{{ $presets->count() }}</span>
                </div>
                @foreach($presetGroups as $group)
                    <div class="list-group-item pl-split-item" data-filter-wrap>
                        <button type="button" class="pl-split-btn" data-filter-key="{{ $group->id }}">
                            <i class="bi {{ $group->icon ?: 'bi-folder' }}"></i> {{ $group->name }}
                        </button>
                        <span class="pl-count">{{ $presetsByGroup->get($group->id, collect())->count() }}</span>
                        <a href="{{ route('admin.departments.preset-groups.edit', [$department, $group]) }}"
                           class="pl-split-edit" title="Изменить группу">
                            <i class="bi bi-pencil"></i>
                        </a>
                    </div>
                @endforeach
                @if($presetGroups->isNotEmpty() && $ungrouped->isNotEmpty())
                    <div class="list-group-item pl-split-item" data-filter-wrap>
                        <button type="button" class="pl-split-btn" data-filter-key="none">
                            <i class="bi bi-inbox"></i> Без группы
                        </button>
                        <span class="pl-count">{{ $ungrouped->count() }}</span>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="col-md-8 col-lg-9">
        <div class="row g-2">
            @foreach($presets as $preset)
                @php $meta = $presetMeta[$preset->id]; @endphp
                {{-- Ключи групп через пробел: пресет может быть в нескольких группах. --}}
                <div class="col-sm-6 col-xl-4" data-group-keys="{{ $preset->groups->isEmpty() ? 'none' : $preset->groups->pluck('id')->implode(' ') }}">
                    <div class="card shadow-sm h-100 pl-card" style="border-top:4px solid {{ $meta['color'] ?? '#dee2e6' }}">
                        <div class="card-body py-2 px-3">
                            <div class="fw-semibold small">{{ $preset->name }}</div>
                            @if($preset->groups->isNotEmpty())
                                <div class="d-flex flex-wrap gap-1 mt-1">
                                    @foreach($preset->groups as $group)
                                        <span class="badge text-bg-light border fw-normal">
                                            <i class="bi {{ $group->icon ?: 'bi-folder' }}"></i> {{ $group->name }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                            <div class="mt-1" style="font-size:.76rem">{{ $meta['products'] ?: '—' }}</div>
                            <div class="text-muted" style="font-size:.72rem">
                                сырьё {{ $meta['raw'] }}@if($meta['package']) · тара {{ $meta['package'] }}@endif
                            </div>
                        </div>
                        <div class="card-footer bg-transparent border-0 pt-0 pb-2 px-3 d-flex justify-content-end">
                            @include('admin.departments.presets._actions')
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="text-center text-muted py-3 small {{ $presets->isEmpty() ? '' : 'd-none' }}" data-filter-empty>
            {{ $presets->isEmpty() ? 'Пресетов нет. Добавьте первый — он появится в форме цеха в блоке «Шаблоны».' : 'В группе пока нет пресетов.' }}
        </div>
    </div>
</div>
