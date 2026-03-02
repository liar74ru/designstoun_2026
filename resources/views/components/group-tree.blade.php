@if(isset($groups) && count($groups) > 0)
    <ul class="tree-view">
        @foreach($groups as $group)
            <li>
                <span class="toggle-btn" data-toggle="group">
                    @if(!empty($group['children']))
                        <i class="bi bi-chevron-right"></i>
                    @else
                        <i class="bi bi-dot" style="visibility: hidden;"></i>
                    @endif
                </span>
                <i class="bi bi-folder folder"></i>
                <a href="{{ route('products.index', ['group' => $group['id']]) }}" class="group-link">
                    {{ $group['name'] }}
                    <span class="badge bg-secondary">{{ $group['total_products'] }}</span>
                    @if($group['products_count'] > 0 && $group['products_count'] != $group['total_products'])
                        <small class="text-muted">(в группе: {{ $group['products_count'] }})</small>
                    @endif
                </a>

                @if(!empty($group['children']))
                    <div class="children" style="display: none;">
                        @include('components.group-tree', ['groups' => $group['children']])
                    </div>
                @endif
            </li>
        @endforeach
    </ul>
@else
    <p class="text-muted text-center py-4">Нет групп для отображения</p>
@endif
