@props([
    'groups' => [],
    'activeGroupId' => null,
    'level' => 0
])

@if(count($groups) > 0)
    @foreach($groups as $group)
        <div class="group-filter-tree-item" data-group-id="{{ $group['id'] }}">
            <div style="display: flex; align-items: flex-start;">
                @if(!empty($group['children']) && count($group['children']) > 0)
                    <button class="group-filter-toggle" type="button">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                @else
                    <span style="width: 24px; flex-shrink: 0; display: inline-block;"></span>
                @endif

                <a href="#"
                   class="group-filter-item"
                   data-group-id="{{ $group['id'] }}"
                   data-group-name="{{ $group['name'] }}"
                   style="
                       flex-grow: 1;
                       min-width: 0;
                       {{ $activeGroupId == $group['id'] ? 'background-color: #0d6efd; color: white;' : '' }}
                   "
                   onmouseover="if('{{ $activeGroupId }}' !== '{{ $group['id'] }}') this.style.backgroundColor='#f8f9fa';"
                   onmouseout="if('{{ $activeGroupId }}' !== '{{ $group['id'] }}') this.style.backgroundColor='transparent';">
                    <span style="display: flex; align-items: center; flex-grow: 1; min-width: 0;">
                        <i class="bi bi-folder" style="margin-right: 8px; flex-shrink: 0; color: {{ $activeGroupId == $group['id'] ? 'white' : '#ffc107' }};"></i>
                        <span style="flex-grow: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $group['name'] }}</span>
                    </span>
                    <span class="badge {{ $activeGroupId == $group['id'] ? 'bg-light text-primary' : 'bg-secondary' }}" style="margin-left: 8px; flex-shrink: 0;">
                        {{ $group['total_products'] }}
                    </span>
                </a>
            </div>

            @if(!empty($group['children']) && count($group['children']) > 0)
                <div class="group-filter-tree-children" style="display: none; margin-left: 24px;">
                    <x-group-filter-tree
                        :groups="$group['children']"
                        :activeGroupId="$activeGroupId"
                        :level="$level + 1"
                    />
                </div>
            @endif
        </div>
    @endforeach
@endif
