@props([
    'groups' => [],
    'activeGroupId' => null,
    'formId' => 'filterForm',
    'inputName' => 'filter[group_id]'
])

<div class="group-filter-wrapper" data-form-id="{{ $formId }}">
    <!-- Скрытое поле для группы -->
    <input type="hidden" name="{{ $inputName }}" class="group-filter-input" value="{{ $activeGroupId ?? '' }}">

    <!-- Кнопка для открытия dropdown -->
    <button class="btn btn-outline-secondary w-100 text-start d-flex justify-content-between align-items-center group-filter-btn"
            type="button"
            style="cursor: pointer;">
        <span class="truncate-text">
            @if($activeGroupId)
                @php
                    $selectedGroup = \App\Models\ProductGroup::where('moysklad_id', $activeGroupId)->first();
                @endphp
                <i class="bi bi-folder me-1"></i>
                {{ $selectedGroup ? $selectedGroup->name : 'Выбрана группа' }}
            @else
                <i class="bi bi-folder me-1"></i>
                Все группы
            @endif
        </span>
        <i class="bi bi-chevron-down ms-2 group-filter-chevron"></i>
    </button>

    <!-- Dropdown меню -->
    <div class="group-filter-menu" style="
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid #dee2e6;
        border-radius: 0.375rem;
        max-height: 400px;
        overflow-y: auto;
        z-index: 1050;
        margin-top: 4px;
        display: none;
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
    ">
        <div style="padding: 8px;">
            <!-- Ссылка на все группы -->
            <a href="#"
               class="group-filter-item"
               data-group-id=""
               data-group-name="Все группы"
               style="
                   display: flex;
                   align-items: center;
                   justify-content: space-between;
                   padding: 8px 12px;
                   border-radius: 4px;
                   text-decoration: none;
                   cursor: pointer;
                   color: #212529;
                   background: transparent;
                   transition: background-color 0.2s;
                   {{ !$activeGroupId ? 'background-color: #0d6efd; color: white;' : '' }}
               "
               onmouseover="if('{{ $activeGroupId }}' !== '') this.style.backgroundColor='#f8f9fa';"
               onmouseout="if('{{ $activeGroupId }}' !== '') this.style.backgroundColor='transparent';">
                <span style="display: flex; align-items: center;">
                    <i class="bi bi-folder" style="margin-right: 8px;"></i>
                    <span>Все группы</span>
                </span>
                <span class="badge {{ !$activeGroupId ? 'bg-light text-primary' : 'bg-secondary' }}" style="margin-left: 8px;">
                    {{ \App\Models\Product::count() }}
                </span>
            </a>

            <div style="border-top: 1px solid #dee2e6; margin: 8px 0;"></div>

            <!-- Дерево групп -->
            <div class="group-filter-tree">
                @include('components.group-filter-tree', [
                    'groups' => $groups,
                    'activeGroupId' => $activeGroupId,
                    'level' => 0
                ])
            </div>
        </div>
    </div>

    <small class="text-muted d-block mt-2">
        <i class="bi bi-info-circle"></i>
        Всего групп: {{ \App\Models\ProductGroup::count() }},
        товаров: {{ \App\Models\Product::count() }}
    </small>
</div>

@once
    <style>
        .group-filter-wrapper {
            position: relative;
        }

        .group-filter-menu {
            width: 100%;
        }

        .group-filter-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px;
            border-radius: 4px;
            text-decoration: none;
            cursor: pointer;
            color: #212529;
            background: transparent;
            transition: background-color 0.2s;
        }

        .group-filter-item:hover {
            background-color: #f8f9fa;
        }

        .group-filter-item.active {
            background-color: #0d6efd;
            color: white;
        }

        .group-filter-item.active:hover {
            background-color: #0b5ed7;
        }

        .group-filter-tree-item {
            margin-bottom: 4px;
        }

        .group-filter-tree-children {
            margin-left: 24px;
        }

        .group-filter-toggle {
            min-width: 24px;
            width: 24px;
            height: 24px;
            padding: 0 !important;
            border: none;
            background: transparent;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .group-filter-toggle:hover {
            background-color: #f0f0f0;
            border-radius: 4px;
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            initGroupFilters();
        });

        function initGroupFilters() {
            // Находим все группы фильтров
            document.querySelectorAll('.group-filter-wrapper').forEach(wrapper => {
                const btn = wrapper.querySelector('.group-filter-btn');
                const menu = wrapper.querySelector('.group-filter-menu');
                const items = wrapper.querySelectorAll('.group-filter-item');
                const toggles = wrapper.querySelectorAll('.group-filter-toggle');

                if (!btn || !menu) return;

                // Открытие/закрытие меню по клику на кнопку
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    const isOpen = menu.style.display !== 'none';
                    menu.style.display = isOpen ? 'none' : 'block';

                    // Обновляем иконку chevron
                    const chevron = btn.querySelector('.group-filter-chevron');
                    if (chevron) {
                        if (isOpen) {
                            chevron.classList.remove('bi-chevron-up');
                            chevron.classList.add('bi-chevron-down');
                        } else {
                            chevron.classList.remove('bi-chevron-down');
                            chevron.classList.add('bi-chevron-up');
                        }
                    }
                });

                // Обработка кликов по элементам фильтра
                items.forEach(item => {
                    item.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();

                        const groupId = this.getAttribute('data-group-id');
                        const groupName = this.getAttribute('data-group-name');

                        selectGroupFilter(groupId, groupName, wrapper);
                    });
                });

                // Обработка переключения (развёртывания) дерева
                toggles.forEach(toggle => {
                    toggle.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        toggleGroupTree(this);
                    });
                });

                // Закрытие меню при клике вне его
                document.addEventListener('click', function(e) {
                    if (!wrapper.contains(e.target)) {
                        menu.style.display = 'none';
                        const chevron = btn.querySelector('.group-filter-chevron');
                        if (chevron) {
                            chevron.classList.remove('bi-chevron-up');
                            chevron.classList.add('bi-chevron-down');
                        }
                    }
                });
            });
        }

        function selectGroupFilter(groupId, groupName, wrapper) {
            const input = wrapper.querySelector('.group-filter-input');
            const btn = wrapper.querySelector('.group-filter-btn');
            const menu = wrapper.querySelector('.group-filter-menu');
            const formId = wrapper.getAttribute('data-form-id');

            // Обновляем значение в скрытом поле
            if (input) {
                input.value = groupId;
            }

            // Обновляем текст на кнопке
            if (groupId) {
                btn.innerHTML = `<span class="truncate-text"><i class="bi bi-folder me-1"></i>${groupName}</span><i class="bi bi-chevron-down ms-2 group-filter-chevron"></i>`;
            } else {
                btn.innerHTML = `<span class="truncate-text"><i class="bi bi-folder me-1"></i>Все группы</span><i class="bi bi-chevron-down ms-2 group-filter-chevron"></i>`;
            }

            // Закрываем меню
            menu.style.display = 'none';

            // Отправляем форму
            const form = document.getElementById(formId);
            if (form) {
                form.submit();
            }
        }

        function toggleGroupTree(button) {
            const item = button.closest('.group-filter-tree-item');
            if (!item) return;

            const children = item.querySelector(':scope > .group-filter-tree-children');
            const icon = button.querySelector('i');

            if (children) {
                const isHidden = children.style.display === 'none' || children.style.display === '';
                children.style.display = isHidden ? 'block' : 'none';

                if (icon) {
                    if (isHidden) {
                        icon.classList.remove('bi-chevron-right');
                        icon.classList.add('bi-chevron-down');
                    } else {
                        icon.classList.remove('bi-chevron-down');
                        icon.classList.add('bi-chevron-right');
                    }
                }
            }
        }
    </script>
@endonce
