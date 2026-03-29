{{-- Мобильный блок: переключатель вида + кнопка новой приёмки --}}
{{-- Параметр $activeTab: 'index' или 'logs' --}}
<div class="card shadow-sm mb-2 d-md-none">
    <div class="card-body p-2">
        <ul class="nav nav-pills mb-2 justify-content-center">
            <li class="nav-item">
                <a class="nav-link {{ ($activeTab ?? '') === 'index' ? 'active' : '' }} py-1 px-3"
                   href="{{ route('stone-receptions.index') }}">
                    <i class="bi bi-table"></i> По партиям
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ ($activeTab ?? '') === 'logs' ? 'active' : '' }} py-1 px-3"
                   href="{{ route('stone-receptions.logs') }}">
                    <i class="bi bi-journal-text"></i> По приёмкам
                </a>
            </li>
        </ul>
        <a href="{{ route('stone-receptions.create') }}" class="btn btn-success w-100">
            <i class="bi bi-plus-circle"></i> Новая приёмка
        </a>
    </div>
</div>
