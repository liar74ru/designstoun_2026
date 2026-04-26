@extends('layouts.app')

@section('title', 'Настройки системы')

@section('content')
<div class="container py-3" style="max-width:720px">

    <x-page-header title="Настройки системы" mobileTitle="Настройки" />

    @include('partials.alerts')

    <form method="POST" action="{{ route('admin.settings.update') }}">
        @csrf

        {{-- Ставка пильщика --}}
        @php $pieceRateSetting = $settings->firstWhere('key', 'PIECE_RATE'); @endphp
        @if($pieceRateSetting)
        <div class="card shadow-sm mb-3">
            <div class="card-header fw-semibold py-2 d-flex justify-content-between align-items-center"
                 role="button"
                 data-block-id="piece-rate">
                <span>Расчёт зарплаты</span>
                <i class="bi bi-chevron-down collapse-icon"></i>
            </div>
            <div class="collapse-content" id="block-piece-rate" style="display: none;">
                <div class="card-body">
                    @php $i = $settings->search(fn($s) => $s->key === 'PIECE_RATE'); @endphp
                    <div class="mb-0">
                        <label for="setting_PIECE_RATE" class="form-label fw-semibold mb-1">
                            {{ $pieceRateSetting->label }}
                        </label>
                        @if($pieceRateSetting->description)
                            <div class="text-muted small mb-1">{{ $pieceRateSetting->description }}</div>
                        @endif
                        <input
                            type="number"
                            step="any"
                            id="setting_PIECE_RATE"
                            name="settings[{{ $i }}][value]"
                            value="{{ old('settings.' . $i . '.value', $pieceRateSetting->value) }}"
                            class="form-control @error('settings.' . $i . '.value') is-invalid @enderror"
                            required
                        >
                        <input type="hidden" name="settings[{{ $i }}][key]" value="PIECE_RATE">
                        @error('settings.' . $i . '.value')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    @php $undercutSetting = $settings->firstWhere('key', 'UNDERCUT_PENALTY'); @endphp
                    @if($undercutSetting)
                        @php $j = $settings->search(fn($s) => $s->key === 'UNDERCUT_PENALTY'); @endphp
                        <div class="mb-0 mt-3">
                            <label for="setting_UNDERCUT_PENALTY" class="form-label fw-semibold mb-1">
                                {{ $undercutSetting->label }}
                            </label>
                            @if($undercutSetting->description)
                                <div class="text-muted small mb-1">{{ $undercutSetting->description }}</div>
                            @endif
                            <input
                                type="number"
                                step="any"
                                id="setting_UNDERCUT_PENALTY"
                                name="settings[{{ $j }}][value]"
                                value="{{ old('settings.' . $j . '.value', $undercutSetting->value) }}"
                                class="form-control @error('settings.' . $j . '.value') is-invalid @enderror"
                                required
                            >
                            <input type="hidden" name="settings[{{ $j }}][key]" value="UNDERCUT_PENALTY">
                            @error('settings.' . $j . '.value')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    @endif
                </div>
            </div>
        </div>
        @endif

        {{-- Себестоимость производства --}}
        @php
            $costKeys = ['BLADE_WEAR', 'RECEPTION_COST', 'PACKAGING_COST', 'WASTE_REMOVAL',
                         'ELECTRICITY', 'PPE_COST', 'FORKLIFT_COST', 'MACHINE_COST', 'RENT_COST', 'OTHER_COSTS'];
            $costSettings = $settings->filter(fn($s) => in_array($s->key, $costKeys));
            $manualTotal = $costSettings->sum(fn($s) => (float) $s->value);
        @endphp
        <div class="card shadow-sm mb-3">
            <div class="card-header fw-semibold py-2 d-flex justify-content-between align-items-center"
                 role="button"
                 data-block-id="costs">
                <span>Себестоимость производства</span>
                <div class="d-flex align-items-center gap-2">
                    <span class="text-muted small fw-normal">ручные затраты: <strong>{{ number_format($manualTotal, 0, ',', ' ') }} ₽/м²</strong></span>
                    <i class="bi bi-chevron-down collapse-icon"></i>
                </div>
            </div>
            <div class="collapse-content" id="block-costs" style="display: none;">
                <div class="card-body">
                    <div class="alert alert-info py-2 px-3 mb-3 small">
                        <i class="bi bi-info-circle"></i>
                        Зарплата пильщика рассчитывается автоматически по коэффициенту продукта и вынесена в блок «Расчёт зарплаты» выше.
                        Остальные компоненты вводятся вручную:
                    </div>

                    @foreach($costSettings as $i => $setting)
                    <div class="mb-3">
                        <label for="setting_{{ $setting->key }}" class="form-label fw-semibold mb-1">
                            {{ $setting->label ?? $setting->key }}
                        </label>
                        @if($setting->description)
                            <div class="text-muted small mb-1">{{ $setting->description }}</div>
                        @endif
                        <input
                            type="number"
                            step="any"
                            id="setting_{{ $setting->key }}"
                            name="settings[{{ $i }}][value]"
                            value="{{ old('settings.' . $i . '.value', $setting->value) }}"
                            class="form-control @error('settings.' . $i . '.value') is-invalid @enderror"
                            required
                        >
                        <input type="hidden" name="settings[{{ $i }}][key]" value="{{ $setting->key }}">
                        @error('settings.' . $i . '.value')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Ставки мастера --}}
        @php
            $masterKeys     = ['MASTER_BASE_RATE', 'MASTER_UNDERCUT_RATE', 'MASTER_PACKAGING_RATE', 'MASTER_SMALL_TILE_RATE'];
            $masterSettings = $settings->filter(fn($s) => in_array($s->key, $masterKeys));
        @endphp
        @if($masterSettings->isNotEmpty())
        <div class="card shadow-sm mb-3">
            <div class="card-header fw-semibold py-2 d-flex justify-content-between align-items-center"
                 role="button"
                 data-block-id="master-rates">
                <span>Ставки мастера</span>
                <i class="bi bi-chevron-down collapse-icon"></i>
            </div>
            <div class="collapse-content" id="block-master-rates" style="display: none;">
                <div class="card-body">
                    @foreach($masterSettings as $setting)
                        @php $i = $settings->search(fn($s) => $s->key === $setting->key); @endphp
                        <div class="mb-3">
                            <label for="setting_{{ $setting->key }}" class="form-label fw-semibold mb-1">
                                {{ $setting->label ?? $setting->key }}
                            </label>
                            @if($setting->description)
                                <div class="text-muted small mb-1">{{ $setting->description }}</div>
                            @endif
                            <input
                                type="number"
                                step="any"
                                id="setting_{{ $setting->key }}"
                                name="settings[{{ $i }}][value]"
                                value="{{ old('settings.' . $i . '.value', $setting->value) }}"
                                class="form-control @error('settings.' . $i . '.value') is-invalid @enderror"
                                required
                            >
                            <input type="hidden" name="settings[{{ $i }}][key]" value="{{ $setting->key }}">
                            @error('settings.' . $i . '.value')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        {{-- МойСклад --}}
        @php
            $moyskladKeys     = ['MOYSKLAD_IN_WORK_STATE', 'MOYSKLAD_DONE_STATE'];
            $moyskladSettings = $settings->filter(fn($s) => in_array($s->key, $moyskladKeys));
        @endphp
        @if($moyskladSettings->isNotEmpty())
        <div class="card shadow-sm mb-3">
            <div class="card-header fw-semibold py-2 d-flex justify-content-between align-items-center"
                 role="button"
                 data-block-id="moysklad">
                <span>МойСклад</span>
                <i class="bi bi-chevron-down collapse-icon"></i>
            </div>
            <div class="collapse-content" id="block-moysklad" style="display: none;">
                <div class="card-body">
                    @foreach($moyskladSettings as $setting)
                        @php $i = $settings->search(fn($s) => $s->key === $setting->key); @endphp
                        <div class="mb-3">
                            <label for="setting_{{ $setting->key }}" class="form-label fw-semibold mb-1">
                                {{ $setting->label ?? $setting->key }}
                            </label>
                            @if($setting->description)
                                <div class="text-muted small mb-1">{{ $setting->description }}</div>
                            @endif
                            <input
                                type="text"
                                id="setting_{{ $setting->key }}"
                                name="settings[{{ $i }}][value]"
                                value="{{ old('settings.' . $i . '.value', $setting->value) }}"
                                class="form-control @error('settings.' . $i . '.value') is-invalid @enderror"
                                required
                            >
                            <input type="hidden" name="settings[{{ $i }}][key]" value="{{ $setting->key }}">
                            @error('settings.' . $i . '.value')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        <div class="d-grid d-md-flex justify-content-md-end mb-4">
            <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-check-lg"></i> Сохранить
            </button>
        </div>
    </form>

    {{-- Склады по умолчанию для отделов (отдельная форма) --}}
    <form method="POST" action="{{ route('admin.departments.store-defaults') }}">
        @csrf
        <div class="card shadow-sm mb-3">
            <div class="card-header fw-semibold py-2 d-flex justify-content-between align-items-center"
                 role="button"
                 data-block-id="dept-stores">
                <span>Склады по умолчанию для отделов</span>
                <i class="bi bi-chevron-down collapse-icon"></i>
            </div>
            <div class="collapse-content" id="block-dept-stores" style="display: none;">
                <div class="card-body p-0">

                    {{-- Десктоп --}}
                    <div class="table-responsive d-none d-md-block">
                        <table class="table table-sm table-bordered mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Отдел</th>
                                    <th>Склад сырья</th>
                                    <th>Склад продукции</th>
                                    <th>Склад производства</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($departments as $dept)
                                <tr>
                                    <td class="ps-3">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <span class="fw-semibold">{{ $dept->name }}</span>
                                            <div class="d-flex gap-1">
                                                <a href="{{ route('admin.departments.show', $dept) }}"
                                                   class="btn btn-sm btn-link p-0 text-primary border-0" title="Просмотр">
                                                    <i class="bi bi-eye-fill"></i>
                                                </a>
                                                <a href="{{ route('admin.departments.show', $dept) }}"
                                                   class="btn btn-sm btn-link p-0 text-secondary border-0" title="Редактировать">
                                                    <i class="bi bi-pencil-fill"></i>
                                                </a>
                                                <form action="{{ route('admin.departments.destroy', $dept) }}" method="POST"
                                                      class="d-inline"
                                                      onsubmit="return confirm('Удалить отдел «{{ $dept->name }}»?')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-link p-0 text-danger border-0" title="Удалить">
                                                        <i class="bi bi-trash-fill"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <select name="departments[{{ $dept->id }}][raw_store_id]"
                                                class="form-select form-select-sm">
                                            <option value="">— не задан —</option>
                                            @foreach($stores as $store)
                                                <option value="{{ $store->id }}"
                                                    {{ $dept->default_raw_store_id === $store->id ? 'selected' : '' }}>
                                                    {{ $store->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td>
                                        <select name="departments[{{ $dept->id }}][product_store_id]"
                                                class="form-select form-select-sm">
                                            <option value="">— не задан —</option>
                                            @foreach($stores as $store)
                                                <option value="{{ $store->id }}"
                                                    {{ $dept->default_product_store_id === $store->id ? 'selected' : '' }}>
                                                    {{ $store->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td>
                                        <select name="departments[{{ $dept->id }}][production_store_id]"
                                                class="form-select form-select-sm">
                                            <option value="">— не задан —</option>
                                            @foreach($stores as $store)
                                                <option value="{{ $store->id }}"
                                                    {{ $dept->default_production_store_id === $store->id ? 'selected' : '' }}>
                                                    {{ $store->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Мобильный --}}
                    <div class="d-md-none">
                        @foreach($departments as $dept)
                        <div class="border-bottom px-3 py-2">
                            <div class="mb-2 d-flex align-items-center justify-content-between">
                                <span class="fw-semibold">{{ $dept->name }}</span>
                                <div class="d-flex gap-1">
                                    <a href="{{ route('admin.departments.show', $dept) }}"
                                       class="btn btn-sm btn-link p-0 text-primary border-0" title="Просмотр">
                                        <i class="bi bi-eye-fill"></i>
                                    </a>
                                    <a href="{{ route('admin.departments.show', $dept) }}"
                                       class="btn btn-sm btn-link p-0 text-secondary border-0" title="Редактировать">
                                        <i class="bi bi-pencil-fill"></i>
                                    </a>
                                    <form action="{{ route('admin.departments.destroy', $dept) }}" method="POST"
                                          class="d-inline"
                                          onsubmit="return confirm('Удалить отдел «{{ $dept->name }}»?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-link p-0 text-danger border-0" title="Удалить">
                                            <i class="bi bi-trash-fill"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small text-muted mb-1">Склад сырья</label>
                                <select name="departments[{{ $dept->id }}][raw_store_id]"
                                        class="form-select form-select-sm">
                                    <option value="">— не задан —</option>
                                    @foreach($stores as $store)
                                        <option value="{{ $store->id }}"
                                            {{ $dept->default_raw_store_id === $store->id ? 'selected' : '' }}>
                                            {{ $store->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small text-muted mb-1">Склад продукции</label>
                                <select name="departments[{{ $dept->id }}][product_store_id]"
                                        class="form-select form-select-sm">
                                    <option value="">— не задан —</option>
                                    @foreach($stores as $store)
                                        <option value="{{ $store->id }}"
                                            {{ $dept->default_product_store_id === $store->id ? 'selected' : '' }}>
                                            {{ $store->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-0">
                                <label class="form-label small text-muted mb-1">Склад производства</label>
                                <select name="departments[{{ $dept->id }}][production_store_id]"
                                        class="form-select form-select-sm">
                                    <option value="">— не задан —</option>
                                    @foreach($stores as $store)
                                        <option value="{{ $store->id }}"
                                            {{ $dept->default_production_store_id === $store->id ? 'selected' : '' }}>
                                            {{ $store->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        @endforeach
                    </div>

                </div>

                {{-- Добавить отдел --}}
                <div class="px-3 py-2 border-top">
                    <a href="{{ route('admin.departments.create') }}"
                       class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-plus-lg"></i> Добавить отдел
                    </a>
                </div>

                <div class="card-footer d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-check-lg"></i> Сохранить склады отделов
                    </button>
                </div>
            </div>
        </div>
    </form>

</div>

<style>
.collapse-icon {
    transition: transform 0.2s ease;
}

.collapse-icon.rotated {
    transform: rotate(180deg);
}

.card-header[role="button"] {
    cursor: pointer;
    user-select: none;
}

.card-header[role="button"]:hover {
    background-color: rgba(0, 0, 0, 0.03);
}

.collapse-content {
    transition: all 0.2s ease;
}
</style>

<script>
(function() {
    const LS_KEY = 'settings_blocks_open';

    function getOpenBlocks() {
        try {
            return JSON.parse(localStorage.getItem(LS_KEY)) || [];
        } catch {
            return [];
        }
    }

    function saveOpenBlocks(ids) {
        localStorage.setItem(LS_KEY, JSON.stringify(ids));
    }

    function toggleBlock(header, content, icon, blockId, forceState = null) {
        const isCurrentlyVisible = content.style.display !== 'none';
        const shouldShow = forceState !== null ? forceState : !isCurrentlyVisible;
        
        if (shouldShow) {
            content.style.display = 'block';
            icon?.classList.add('rotated');
            
            // Сохраняем состояние
            const openBlocks = getOpenBlocks();
            if (!openBlocks.includes(blockId)) {
                openBlocks.push(blockId);
                saveOpenBlocks(openBlocks);
            }
        } else {
            content.style.display = 'none';
            icon?.classList.remove('rotated');
            
            // Сохраняем состояние
            const openBlocks = getOpenBlocks();
            const index = openBlocks.indexOf(blockId);
            if (index !== -1) {
                openBlocks.splice(index, 1);
                saveOpenBlocks(openBlocks);
            }
        }
    }

    // Инициализация после полной загрузки DOM
    document.addEventListener('DOMContentLoaded', function() {
        const openBlocks = getOpenBlocks();
        
        // Инициализируем все блоки
        document.querySelectorAll('.card-header[role="button"]').forEach(header => {
            const blockId = header.dataset.blockId;
            const content = document.getElementById(`block-${blockId}`);
            const icon = header.querySelector('.collapse-icon');
            
            if (!content) return;
            
            // Проверяем, должен ли блок быть открыт
            const shouldBeOpen = openBlocks.includes(blockId);
            
            // Устанавливаем начальное состояние без анимации
            if (shouldBeOpen) {
                content.style.display = 'block';
                icon?.classList.add('rotated');
            } else {
                content.style.display = 'none';
                icon?.classList.remove('rotated');
            }
            
            // Добавляем обработчик клика
            header.addEventListener('click', function(e) {
                // Предотвращаем всплытие, чтобы случайно не сработали другие обработчики
                e.stopPropagation();
                toggleBlock(header, content, icon, blockId);
            });
        });
    });
})();
</script>
@endsection