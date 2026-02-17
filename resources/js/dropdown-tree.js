// Улучшенное управление дропдауном с деревом
document.addEventListener('DOMContentLoaded', function() {
    const dropdownTree = document.getElementById('groupFilterDropdown');

    if (dropdownTree) {
        const dropdownBtn = document.getElementById('groupDropdownBtn');
        const dropdownMenu = dropdownTree.querySelector('.dropdown-menu');

        // Открытие по клику
        dropdownBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const isExpanded = this.getAttribute('aria-expanded') === 'true';

            if (!isExpanded) {
                // Закрываем другие дропдауны
                document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
                    if (menu !== dropdownMenu) {
                        menu.classList.remove('show');
                    }
                });

                dropdownMenu.classList.add('show');
                this.setAttribute('aria-expanded', 'true');
            } else {
                dropdownMenu.classList.remove('show');
                this.setAttribute('aria-expanded', 'false');
            }
        });

        // Закрытие при клике вне дропдауна
        document.addEventListener('click', function(e) {
            if (!dropdownTree.contains(e.target)) {
                dropdownMenu.classList.remove('show');
                dropdownBtn.setAttribute('aria-expanded', 'false');
            }
        });

        // Предотвращаем закрытие при клике внутри меню
        dropdownMenu.addEventListener('click', function(e) {
            e.stopPropagation();
        });

        // Открытие при наведении (опционально)
        dropdownTree.addEventListener('mouseenter', function() {
            // Можно добавить задержку перед открытием
            setTimeout(() => {
                if (!dropdownMenu.classList.contains('show')) {
                    dropdownMenu.classList.add('show');
                    dropdownBtn.setAttribute('aria-expanded', 'true');
                }
            }, 200);
        });

        dropdownTree.addEventListener('mouseleave', function() {
            // Закрываем при уходе мыши с задержкой
            setTimeout(() => {
                if (!dropdownTree.matches(':hover') && !dropdownMenu.matches(':hover')) {
                    dropdownMenu.classList.remove('show');
                    dropdownBtn.setAttribute('aria-expanded', 'false');
                }
            }, 300);
        });
    }
});
