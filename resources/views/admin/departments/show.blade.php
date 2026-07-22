@extends('layouts.app')

@section('title', $department->name . ' — отдел')

@section('content')
<div class="container py-3" style="max-width:720px">

    <x-page-header
        title="{{ $department->name }}"
        mobileTitle="{{ $department->name }}"
        :backUrl="route('admin.settings.index')"
        backLabel="К настройкам" />

    @include('partials.alerts')

    {{-- 1. Форма редактирования --}}
    <div class="card shadow-sm mb-3">
        <div class="card-header fw-semibold py-2">Редактировать отдел</div>
        <div class="card-body p-4">
            <form method="POST" action="{{ route('admin.departments.update', $department) }}">
                @csrf
                @method('PATCH')

                <div class="mb-3">
                    <label for="name" class="form-label fw-semibold">
                        Название <span class="text-danger">*</span>
                    </label>
                    <input type="text"
                           id="name" name="name"
                           value="{{ old('name', $department->name) }}"
                           class="form-control @error('name') is-invalid @enderror"
                           style="border-radius:.4rem"
                           required>
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="code" class="form-label fw-semibold">Код</label>
                    <input type="text"
                           id="code" name="code"
                           value="{{ old('code', $department->code) }}"
                           class="form-control @error('code') is-invalid @enderror"
                           style="border-radius:.4rem">
                    @error('code')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="description" class="form-label fw-semibold">Описание</label>
                    <textarea id="description" name="description" rows="3"
                              class="form-control @error('description') is-invalid @enderror"
                              style="border-radius:.4rem">{{ old('description', $department->description) }}</textarea>
                    @error('description')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="manager_id" class="form-label fw-semibold">Руководитель</label>
                    <select id="manager_id" name="manager_id"
                            class="form-select @error('manager_id') is-invalid @enderror"
                            style="border-radius:.4rem">
                        <option value="">— не назначен —</option>
                        @foreach($allWorkers as $worker)
                            <option value="{{ $worker->id }}"
                                {{ old('manager_id', $department->manager_id) == $worker->id ? 'selected' : '' }}>
                                {{ $worker->name }}
                                @if($worker->department_id === $department->id)
                                    (этот отдел)
                                @endif
                            </option>
                        @endforeach
                    </select>
                    @error('manager_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-4">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox"
                               id="is_active" name="is_active" value="1"
                               {{ old('is_active', $department->is_active) ? 'checked' : '' }}>
                        <label class="form-check-label fw-semibold" for="is_active">
                            Отдел активен
                        </label>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Сохранить
                    </button>
                    <a href="{{ route('admin.settings.index') }}" class="btn btn-outline-secondary">
                        Отмена
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- 2. Операции в шапке --}}
    <div class="card shadow-sm mb-3">
        <div class="card-header fw-semibold py-2">Права на операции по позициям</div>
        <div class="card-body p-2 p-md-3">
            <form method="POST" action="{{ route('admin.departments.operations.update', $department) }}">
                @csrf
                @method('PATCH')

                @php
                    $columns = [
                        'Мастер'           => ['short' => 'Мастер',  'full' => 'Мастер'],
                        'Помощник мастера' => ['short' => 'Помощ.',  'full' => 'Помощник мастера'],
                    ];
                @endphp

                <table class="table table-sm align-middle mb-0" style="table-layout:fixed">
                    <thead class="table-light">
                        <tr>
                            <th style="font-size:.8rem">Операция</th>
                            @foreach($columns as $colKey => $col)
                                <th class="text-center"
                                    title="{{ $col['full'] }}"
                                    style="width:62px;font-size:.75rem;white-space:nowrap">
                                    {{ $col['short'] }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($operations as $key => $op)
                            @php
                                $configurable    = $op['configurable_positions'] ?? [];
                                $alwaysFor       = $op['positions_always_visible'] ?? [];
                                $isAdminOnly     = ! empty($op['admin_only']);
                                $allowed         = $allowedPositions[$key] ?? [];
                            @endphp
                            <tr>
                                <td style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                                    <i class="bi {{ $op['icon'] }} me-1"></i><span class="small">{{ $op['label'] }}</span>
                                    @if($isAdminOnly)
                                        <span class="badge bg-secondary ms-1" style="font-size:.65rem">админ</span>
                                    @elseif(! empty($alwaysFor))
                                        <span class="badge bg-info text-dark ms-1" style="font-size:.65rem"
                                              title="Всегда видна для: {{ implode(', ', $alwaysFor) }}">всегда</span>
                                    @endif
                                </td>
                                @foreach(array_keys($columns) as $pos)
                                    <td class="text-center" style="width:62px">
                                        @if($isAdminOnly)
                                            <input class="form-check-input" type="checkbox" disabled
                                                   title="Только администратор">
                                        @elseif(in_array($pos, $alwaysFor, true))
                                            <input class="form-check-input" type="checkbox" disabled checked
                                                   title="Всегда видна для этой позиции">
                                        @elseif(in_array($pos, $configurable, true))
                                            <input type="hidden" name="operations[{{ $key }}][positions][]" value="">
                                            <input class="form-check-input" type="checkbox"
                                                   name="operations[{{ $key }}][positions][]"
                                                   value="{{ $pos }}"
                                                   {{ in_array($pos, $allowed, true) ? 'checked' : '' }}>
                                        @else
                                            <span class="text-muted small">—</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="text-muted mt-2" style="font-size:.75rem">
                    <span class="badge bg-secondary" style="font-size:.65rem">админ</span> — операция доступна только администратору.
                    <span class="badge bg-info text-dark" style="font-size:.65rem">всегда</span> — захардкоженная видимость, не настраивается.
                </div>

                <div class="mt-3 d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-check-lg"></i> Сохранить
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- 3. Список сотрудников --}}
    <div class="card shadow-sm mb-3">
        <div class="card-header fw-semibold py-2">
            Сотрудники ({{ $workers->count() }})
        </div>
        <div class="card-body p-0">
            @if($workers->isEmpty())
                <p class="text-muted small px-3 py-3 mb-0">В отделе нет сотрудников.</p>
            @else
                {{-- Десктоп --}}
                <div class="table-responsive d-none d-md-block">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Имя</th>
                                <th>Должность</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($workers as $worker)
                            <tr>
                                <td class="ps-3 fw-semibold">{{ $worker->name }}</td>
                                <td class="text-muted small">{{ $worker->position }}</td>
                                <td class="text-end pe-3">
                                    <a href="{{ route('workers.edit', $worker) }}"
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{-- Мобильный --}}
                <div class="d-md-none">
                    @foreach($workers as $worker)
                    <div class="d-flex justify-content-between align-items-center border-bottom px-3 py-2">
                        <div>
                            <div class="fw-semibold small">{{ $worker->name }}</div>
                            <div class="text-muted" style="font-size:.8rem">{{ $worker->position }}</div>
                        </div>
                        <a href="{{ route('workers.edit', $worker) }}"
                           class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-pencil"></i>
                        </a>
                    </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- 4. Склады по умолчанию --}}
    <div class="card shadow-sm mb-3">
        <div class="card-header fw-semibold py-2">Склады по умолчанию</div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.departments.store-defaults') }}">
                @csrf

                {{-- Десктоп --}}
                <div class="d-none d-md-block">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Склад сырья</label>
                            <select name="departments[{{ $department->id }}][raw_store_id]"
                                    class="form-select form-select-sm"
                                    style="border-radius:.4rem">
                                <option value="">— не задан —</option>
                                @foreach($stores as $store)
                                    <option value="{{ $store->id }}"
                                        {{ $department->default_raw_store_id === $store->id ? 'selected' : '' }}>
                                        {{ $store->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Склад продукции</label>
                            <select name="departments[{{ $department->id }}][product_store_id]"
                                    class="form-select form-select-sm"
                                    style="border-radius:.4rem">
                                <option value="">— не задан —</option>
                                @foreach($stores as $store)
                                    <option value="{{ $store->id }}"
                                        {{ $department->default_product_store_id === $store->id ? 'selected' : '' }}>
                                        {{ $store->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Склад производства</label>
                            <select name="departments[{{ $department->id }}][production_store_id]"
                                    class="form-select form-select-sm"
                                    style="border-radius:.4rem">
                                <option value="">— не задан —</option>
                                @foreach($stores as $store)
                                    <option value="{{ $store->id }}"
                                        {{ $department->default_production_store_id === $store->id ? 'selected' : '' }}>
                                        {{ $store->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                {{-- Мобильный --}}
                <div class="d-md-none">
                    <div class="mb-2">
                        <label class="form-label small text-muted mb-1">Склад сырья</label>
                        <select name="departments[{{ $department->id }}][raw_store_id]"
                                class="form-select form-select-sm" style="border-radius:.4rem">
                            <option value="">— не задан —</option>
                            @foreach($stores as $store)
                                <option value="{{ $store->id }}"
                                    {{ $department->default_raw_store_id === $store->id ? 'selected' : '' }}>
                                    {{ $store->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small text-muted mb-1">Склад продукции</label>
                        <select name="departments[{{ $department->id }}][product_store_id]"
                                class="form-select form-select-sm" style="border-radius:.4rem">
                            <option value="">— не задан —</option>
                            @foreach($stores as $store)
                                <option value="{{ $store->id }}"
                                    {{ $department->default_product_store_id === $store->id ? 'selected' : '' }}>
                                    {{ $store->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-muted mb-1">Склад производства</label>
                        <select name="departments[{{ $department->id }}][production_store_id]"
                                class="form-select form-select-sm" style="border-radius:.4rem">
                            <option value="">— не задан —</option>
                            @foreach($stores as $store)
                                <option value="{{ $store->id }}"
                                    {{ $department->default_production_store_id === $store->id ? 'selected' : '' }}>
                                    {{ $store->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="mt-3 d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-check-lg"></i> Сохранить склады
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- 5. Пресеты цеха --}}
    <div class="card shadow-sm mb-3">
        <div class="card-header d-flex justify-content-between align-items-center py-2">
            <span class="fw-semibold">Пресеты цеха ({{ $presets->count() }})</span>
            <a href="{{ route('admin.departments.presets.create', $department) }}"
               class="btn btn-sm btn-outline-primary">
                <i class="bi bi-plus-circle"></i> Добавить
            </a>
        </div>
        <div class="card-body p-0">
            @forelse($presets as $preset)
                @php
                    $rawCount     = $preset->items->where('role', \App\Models\WorkshopItem::ROLE_RAW)->count();
                    $packageCount = $preset->items->where('role', \App\Models\WorkshopItem::ROLE_PACKAGE)->count();
                    $productCount = $preset->items->where('role', \App\Models\WorkshopItem::ROLE_PRODUCT)->count();
                    $prodItem     = $preset->items->firstWhere('role', \App\Models\WorkshopItem::ROLE_PRODUCT);
                    $skuColor     = \App\Models\Product::getColorBySku($prodItem?->product?->sku);
                    $skuBg        = $skuColor === '#FFFFFF' ? '' : $skuColor . '18';
                @endphp
                <div class="px-3 py-2 {{ !$loop->last ? 'border-bottom' : '' }}"
                     style="border-left:4px solid {{ $skuColor }};{{ $skuBg ? 'background:'.$skuBg.';' : '' }}">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="me-2">
                            <div class="fw-semibold small">{{ $preset->name }}</div>
                            <div class="text-muted" style="font-size:.75rem">
                                сырьё: {{ $rawCount }} · тара: {{ $packageCount }} · продукт: {{ $productCount }}
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <form method="POST" action="{{ route('admin.departments.presets.copy', [$department, $preset]) }}"
                                  class="d-flex align-items-center gap-1">
                                @csrf
                                <select name="target_department_id" class="form-select form-select-sm"
                                        style="border-radius:.4rem;width:auto">
                                    @foreach($allDepartments as $dept)
                                        <option value="{{ $dept->id }}" {{ $dept->id === $department->id ? 'selected' : '' }}>
                                            {{ $dept->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Копировать в выбранный отдел">
                                    <i class="bi bi-copy"></i>
                                </button>
                            </form>
                            <a href="{{ route('admin.departments.presets.edit', [$department, $preset]) }}"
                               class="btn btn-sm btn-outline-primary" title="Изменить">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="POST" action="{{ route('admin.departments.presets.destroy', [$department, $preset]) }}"
                                  onsubmit="return confirm('Удалить пресет «{{ $preset->name }}»?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Удалить">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            @empty
                <div class="text-center text-muted py-3 small">
                    Пресетов нет. Добавьте первый — он появится в форме цеха в блоке «Шаблоны».
                </div>
            @endforelse
        </div>
    </div>

    {{-- Зона удаления (только если нет сотрудников) --}}
    @if($workers->isEmpty())
    <div class="card shadow-sm border-danger mb-3">
        <div class="card-body d-flex justify-content-between align-items-center py-2 px-3">
            <span class="text-muted small">Удалить отдел навсегда</span>
            <form method="POST" action="{{ route('admin.departments.destroy', $department) }}"
                  onsubmit="return confirm('Удалить отдел «{{ $department->name }}»? Это действие необратимо.')">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-trash"></i> Удалить отдел
                </button>
            </form>
        </div>
    </div>
    @endif

</div>
@endsection
