// Функция для инициализации дерева групп
function initTreeView() {
    console.log('Initializing tree view'); // Для отладки

    // Находим все кнопки-переключатели
    const toggleButtons = document.querySelectorAll('.tree-view .toggle-btn');

    toggleButtons.forEach(button => {
        // Удаляем старые обработчики
        button.removeEventListener('click', toggleGroupHandler);
        // Добавляем новый обработчик
        button.addEventListener('click', toggleGroupHandler);
    });

    // Скрываем все дочерние элементы при загрузке
    document.querySelectorAll('.tree-view .children').forEach(child => {
        child.style.display = 'none';
    });
}

// Обработчик клика по кнопке
function toggleGroupHandler(event) {
    event.preventDefault();
    event.stopPropagation();

    const button = event.currentTarget;
    const li = button.closest('li');

    if (!li) return;

    const childrenDiv = li.querySelector(':scope > .children');
    const icon = button.querySelector('i');

    console.log('Toggle clicked', { button, childrenDiv, icon }); // Для отладки

    if (childrenDiv) {
        if (childrenDiv.style.display === 'none' || childrenDiv.style.display === '') {
            // Раскрываем
            childrenDiv.style.display = 'block';
            if (icon) {
                icon.classList.remove('bi-chevron-right');
                icon.classList.add('bi-chevron-down');
            }
        } else {
            // Скрываем
            childrenDiv.style.display = 'none';
            if (icon) {
                icon.classList.remove('bi-chevron-down');
                icon.classList.add('bi-chevron-right');
            }
        }
    }
}

// Инициализация после загрузки страницы
document.addEventListener('DOMContentLoaded', function() {
    initTreeView();
});

// Также инициализируем после каждого AJAX-запроса (если используете)
document.addEventListener('ajaxComplete', function() {
    initTreeView();
});
