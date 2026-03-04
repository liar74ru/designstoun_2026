{{-- resources/views/components/group-tree.blade.php --}}
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
                <a href="{{ route('products.index', ['filter' => ['group_id' => $group['id']]]) }}"
                   class="group-link"
                   title="Показать товары этой группы и всех подгрупп">
                    {{ $group['name'] }}

                    {{-- Основной счетчик --}}
                    <span class="badge bg-secondary"
                          title="Всего товаров в группе и подгруппах">
                        {{ $group['total_products'] }}
                    </span>

                    {{-- Дополнительный счетчик для товаров только в этой группе --}}
                    @if($group['products_count'] > 0 && $group['products_count'] != $group['total_products'])
                        <small class="text-muted"
                               title="Товары только в этой группе (без подгрупп)">
                            (+{{ $group['products_count'] }} в группе)
                        </small>
                    @endif

                    {{-- Если есть только товары в этой группе --}}
                    @if($group['products_count'] > 0 && empty($group['children']))
                        <small class="text-muted">
                            ({{ $group['products_count'] }} {{ trans_choice('товар|товара|товаров', $group['products_count']) }})
                        </small>
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
    <p class="text-muted text-center py-4">
        <i class="bi bi-folder2-open"></i>
        Нет групп для отображения
    </p>
@endif
