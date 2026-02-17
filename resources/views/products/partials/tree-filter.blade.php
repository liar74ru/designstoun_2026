@if(isset($groups) && count($groups) > 0)
    <div class="tree-filter" style="max-width: 100%;">
        @foreach($groups as $group)
            <div class="tree-filter-item mb-1" data-group-id="{{ $group['id'] }}">
                <div class="d-flex align-items-start">
                    @if(!empty($group['children']))
                        <button class="btn btn-sm p-0 tree-filter-toggle"
                                type="button"
                                style="width: 24px; height: 24px; flex-shrink: 0; border: none; background: transparent; cursor: pointer;"
                                onclick="toggleGroup(this, event)">
                            <i class="bi bi-chevron-right"></i>
                        </button>
                    @else
                        <span style="width: 24px; flex-shrink: 0; display: inline-block;"></span>
                    @endif

                    <a href="{{ route('products.index', array_merge(request()->except(['page']), ['group' => $group['id']])) }}"
                       class="text-decoration-none d-flex align-items-center flex-grow-1 py-1 px-2 rounded group-link"
                       style="min-width: 0; {{ request('group') == $group['id'] ? 'background-color: #0d6efd; color: white;' : 'color: #212529;' }}"
                       onmouseover="this.style.backgroundColor='{{ request('group') == $group['id'] ? '#0b5ed7' : '#f8f9fa' }}'"
                       onmouseout="this.style.backgroundColor='{{ request('group') == $group['id'] ? '#0d6efd' : 'transparent' }}'">
                        <i class="bi bi-folder me-1 flex-shrink-0" style="color: {{ request('group') == $group['id'] ? 'white' : '#ffc107' }};"></i>
                        <span class="flex-grow-1 text-truncate">{{ $group['name'] }}</span>
                        <span class="badge {{ request('group') == $group['id'] ? 'bg-light text-primary' : 'bg-secondary' }} ms-2 flex-shrink-0">
                            {{ $group['total_products'] }}
                        </span>
                    </a>
                </div>

                @if(!empty($group['children']))
                    <div class="tree-filter-children" style="display: none; margin-left: 24px;">
                        @include('products.partials.tree-filter', ['groups' => $group['children']])
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@else
    <p class="text-muted text-center py-3">Нет групп для отображения</p>
@endif

<script>
    // Глобальная функция для переключения групп
    function toggleGroup(button, event) {
        event.preventDefault();
        event.stopPropagation();

        const item = button.closest('.tree-filter-item');
        if (!item) return;

        const children = item.querySelector(':scope > .tree-filter-children');
        const icon = button.querySelector('i');

        if (children) {
            if (children.style.display === 'none' || children.style.display === '') {
                children.style.display = 'block';
                if (icon) {
                    icon.classList.remove('bi-chevron-right');
                    icon.classList.add('bi-chevron-down');
                }
            } else {
                children.style.display = 'none';
                if (icon) {
                    icon.classList.remove('bi-chevron-down');
                    icon.classList.add('bi-chevron-right');
                }
            }
        }
    }

    // Инициализация при загрузке страницы
    document.addEventListener('DOMContentLoaded', function() {
        // Скрываем все дочерние элементы
        document.querySelectorAll('.tree-filter-children').forEach(function(el) {
            el.style.display = 'none';
        });
    });
</script>
