{{-- Мобильный блок: переключатель вида + кнопка новой приёмки --}}
{{-- Параметр $activeTab: 'index' или 'logs' --}}
<div class="card shadow-sm mb-2 d-md-none">
    <div class="card-body p-2">
        <div class="btn-group w-100 mb-2">
            <a class="btn {{ ($activeTab ?? '') === 'index' ? 'btn-primary' : 'btn-outline-secondary' }} py-1"
               href="{{ route('stone-receptions.index') }}">
                <i class="bi bi-table"></i> По партиям
            </a>
            <a class="btn {{ ($activeTab ?? '') === 'logs' ? 'btn-primary' : 'btn-outline-secondary' }} py-1"
               href="{{ route('stone-receptions.logs') }}">
                <i class="bi bi-journal-text"></i> По приёмкам
            </a>
        </div>
        <a href="{{ route('stone-receptions.create') }}" class="btn btn-success w-100">
            <i class="bi bi-plus-circle"></i> Новая приёмка
        </a>
    </div>
</div>
