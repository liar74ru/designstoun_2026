{{--
    Общая форма правила себестоимости (create/edit).
    Параметры: $action, $method ('POST'|'PATCH'), $department, $modifier (null при создании).
--}}
@php
    use App\Models\DepartmentModifier as Rule;

    $trigger = old('trigger', $modifier?->trigger ?? Rule::TRIGGER_MANUAL);
    $color   = old('color', $modifier?->color);
@endphp

@push('styles')
    <style>
        /* Поля переключаются по способу срабатывания: без этого до старта Alpine видны оба. */
        [x-cloak]{display:none!important}

        /* Палитра плашек: выбор — целиком на CSS, чтобы работал и до старта JS. */
        .modifier-color{display:inline-block;line-height:0}
        .modifier-color__dot{
            display:inline-flex;align-items:center;justify-content:center;
            flex:0 0 auto;width:32px;height:32px;border-radius:50%;
            border:1px solid rgba(0,0,0,.15);cursor:pointer;font-size:.85rem;
        }
        .modifier-color input:checked + .modifier-color__dot{box-shadow:0 0 0 3px rgba(13,110,253,.4)}
        .modifier-color input:focus-visible + .modifier-color__dot{outline:2px solid #0D6EFD;outline-offset:2px}
    </style>
@endpush

<form method="POST" action="{{ $action }}" data-submit-guard
      x-data="{ trigger: '{{ $trigger }}' }">
    @csrf
    @if($method !== 'POST')
        @method($method)
    @endif

    {{-- ① Что это --}}
    <div class="card shadow-sm mb-3">
        <div class="card-header fw-semibold py-2">
            <i class="bi bi-tag me-1"></i> Правило
        </div>
        <div class="card-body">
            <div class="mb-3">
                <label for="modName" class="form-label fw-semibold">
                    Название <span class="text-danger">*</span>
                </label>
                <input type="text" id="modName" name="name"
                       value="{{ old('name', $modifier?->name) }}"
                       class="form-control @error('name') is-invalid @enderror"
                       style="border-radius:.4rem" maxlength="100" required>
                @error('name')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-text" style="font-size:.72rem">
                    Короткая подпись плашки, например «Подкол &gt; 80%».
                </div>
            </div>

            <div class="mb-3">
                <label for="modKey" class="form-label fw-semibold">
                    Ключ <span class="text-danger">*</span>
                </label>
                <input type="text" id="modKey" name="key"
                       value="{{ old('key', $modifier?->key) }}"
                       class="form-control @error('key') is-invalid @enderror"
                       style="border-radius:.4rem" maxlength="64" required>
                @error('key')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-text" style="font-size:.72rem">
                    Латиница в нижнем регистре, цифры и подчёркивание. Уникален в пределах отдела.
                    Ключи <code>undercut</code> и <code>edging</code> связаны с чекбоксами «подкол»
                    и «торцовка» в формах приёмки и цеха — если их переименовать, надбавка
                    перестанет применяться.
                </div>
            </div>

            <div class="mb-1">
                <label class="form-label fw-semibold">Цвет плашки</label>
                <div class="d-flex flex-wrap gap-2">
                    @foreach(Rule::COLORS as $hex => $label)
                        <label class="modifier-color" title="{{ $label }}">
                            <input type="radio" name="color" value="{{ $hex }}" class="visually-hidden"
                                   @checked($color === $hex)>
                            <span class="modifier-color__dot" style="background:{{ $hex }}"></span>
                        </label>
                    @endforeach
                    <label class="modifier-color" title="Без плашки">
                        <input type="radio" name="color" value="" class="visually-hidden"
                               @checked($color === null || $color === '')>
                        <span class="modifier-color__dot text-muted">
                            <i class="bi bi-slash-circle"></i>
                        </span>
                    </label>
                </div>
                @error('color')
                    <div class="text-danger small mt-1">{{ $message }}</div>
                @enderror
            </div>
        </div>
    </div>

    {{-- ② Когда срабатывает --}}
    <div class="card shadow-sm mb-3">
        <div class="card-header fw-semibold py-2">
            <i class="bi bi-lightning me-1"></i> Когда срабатывает
        </div>
        <div class="card-body">
            <div class="mb-3">
                <label for="modTrigger" class="form-label fw-semibold">Способ</label>
                <select id="modTrigger" name="trigger" class="form-select @error('trigger') is-invalid @enderror"
                        style="border-radius:.4rem" x-model="trigger">
                    <option value="{{ Rule::TRIGGER_MANUAL }}">Вручную — чекбоксом в форме</option>
                    <option value="{{ Rule::TRIGGER_SKU }}">Автоматически — по маске SKU продукта</option>
                </select>
                @error('trigger')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3" x-show="trigger === '{{ Rule::TRIGGER_SKU }}'" x-cloak>
                <label for="modSkuPattern" class="form-label fw-semibold">
                    Маска SKU <span class="text-danger">*</span>
                </label>
                <input type="text" id="modSkuPattern" name="sku_pattern"
                       value="{{ old('sku_pattern', $modifier?->sku_pattern) }}"
                       class="form-control @error('sku_pattern') is-invalid @enderror"
                       style="border-radius:.4rem" maxlength="32" placeholder="04-07-*">
                @error('sku_pattern')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-text" style="font-size:.72rem">
                    <code>*</code> заменяет один сегмент SKU целиком: <code>04-07-*</code>,
                    <code>*-*-30</code>.
                </div>
            </div>

            <div class="mb-3" x-show="trigger === '{{ Rule::TRIGGER_MANUAL }}'" x-cloak>
                <label for="modBatchSku" class="form-label fw-semibold">Только для партий с SKU</label>
                <input type="text" id="modBatchSku" name="available_when_batch_sku"
                       value="{{ old('available_when_batch_sku', $modifier?->available_when_batch_sku) }}"
                       class="form-control @error('available_when_batch_sku') is-invalid @enderror"
                       style="border-radius:.4rem" maxlength="32" placeholder="04-*">
                @error('available_when_batch_sku')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-text" style="font-size:.72rem">
                    Пусто — правило доступно при любой партии.
                </div>
            </div>

            <div>
                <label for="modAppliesTo" class="form-label fw-semibold">Область действия</label>
                <select id="modAppliesTo" name="applies_to"
                        class="form-select @error('applies_to') is-invalid @enderror"
                        style="border-radius:.4rem">
                    @php $appliesTo = old('applies_to', $modifier?->applies_to ?? Rule::SCOPE_BOTH); @endphp
                    <option value="{{ Rule::SCOPE_BOTH }}"      @selected($appliesTo === Rule::SCOPE_BOTH)>Приёмка и цех</option>
                    <option value="{{ Rule::SCOPE_RECEPTION }}" @selected($appliesTo === Rule::SCOPE_RECEPTION)>Только приёмка</option>
                    <option value="{{ Rule::SCOPE_WORKSHOP }}"  @selected($appliesTo === Rule::SCOPE_WORKSHOP)>Только цех</option>
                </select>
                @error('applies_to')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>
    </div>

    {{-- ③ На что влияет --}}
    <div class="card shadow-sm mb-3"
         x-data="{
            workerDelta: '{{ old('worker_coeff_delta', $modifier?->worker_coeff_delta) }}',
            workerReplace: '{{ old('worker_coeff_replace', $modifier?->worker_coeff_replace) }}',
            get preview() {
                const rates = window.RateFormula;
                if (!rates) return null;

                const rate = rates.pieceRate({{ $department->id }});
                const base = 2;
                const eff  = this.workerReplace !== ''
                    ? parseFloat(this.workerReplace)
                    : base + (parseFloat(this.workerDelta) || 0);

                if (isNaN(eff)) return null;

                return {
                    rate,
                    before: rates.stepped(rate, base),
                    after:  rates.stepped(rate, eff),
                };
            }
         }">
        <div class="card-header fw-semibold py-2">
            <i class="bi bi-cash-coin me-1"></i> На что влияет
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">
                Правило меняет коэффициент, а не рубли: при подъёме базовой ставки отдела
                надбавка пересчитывается сама. «Прибавить» складывается с коэффициентом продукта,
                «заменить» — подменяет всё, что накоплено правилами до этого.
            </p>

            @foreach(['worker' => 'Пильщик', 'master' => 'Мастер'] as $role => $roleLabel)
                <div class="mb-3">
                    <div class="fw-semibold small mb-1">{{ $roleLabel }}</div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small mb-1" for="mod-{{ $role }}-delta">Прибавить</label>
                            <input type="number" step="0.0001" id="mod-{{ $role }}-delta"
                                   name="{{ $role }}_coeff_delta"
                                   value="{{ old($role . '_coeff_delta', $modifier?->{$role . '_coeff_delta'}) }}"
                                   class="form-control form-control-sm @error($role . '_coeff_delta') is-invalid @enderror"
                                   style="border-radius:.4rem" placeholder="—"
                                   @if($role === 'worker') x-model="workerDelta" @endif>
                            @error($role . '_coeff_delta')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-1" for="mod-{{ $role }}-replace">Заменить</label>
                            <input type="number" step="0.0001" id="mod-{{ $role }}-replace"
                                   name="{{ $role }}_coeff_replace"
                                   value="{{ old($role . '_coeff_replace', $modifier?->{$role . '_coeff_replace'}) }}"
                                   class="form-control form-control-sm @error($role . '_coeff_replace') is-invalid @enderror"
                                   style="border-radius:.4rem" placeholder="—"
                                   @if($role === 'worker') x-model="workerReplace" @endif>
                            @error($role . '_coeff_replace')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>
            @endforeach

            <div class="alert alert-light border py-2 mb-0 small" x-show="preview" x-cloak>
                <template x-if="preview">
                    <span>
                        Пример: ставка отдела <span x-text="preview.rate"></span> ₽ и коэффициент
                        продукта 2 — было <span class="fw-semibold" x-text="preview.before"></span> ₽/м²,
                        с этим правилом <span class="fw-semibold" x-text="preview.after"></span> ₽/м².
                    </span>
                </template>
            </div>
        </div>
    </div>

    {{-- ④ Порядок и активность --}}
    <div class="card shadow-sm mb-3">
        <div class="card-header fw-semibold py-2">
            <i class="bi bi-sort-numeric-down me-1"></i> Порядок применения
        </div>
        <div class="card-body">
            <div class="mb-3">
                <label for="modSortOrder" class="form-label fw-semibold">
                    Порядок <span class="text-danger">*</span>
                </label>
                <input type="number" step="1" min="0" max="65535" id="modSortOrder" name="sort_order"
                       value="{{ old('sort_order', $modifier?->sort_order ?? 50) }}"
                       class="form-control @error('sort_order') is-invalid @enderror"
                       style="border-radius:.4rem;max-width:140px" required>
                @error('sort_order')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-text" style="font-size:.72rem">
                    Правила применяются по возрастанию. Порядок важен: «заменить» обнуляет всё,
                    что прибавили правила с меньшим номером.
                </div>
            </div>

            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="modIsActive" name="is_active" value="1"
                       {{ old('is_active', $modifier?->is_active ?? true) ? 'checked' : '' }}>
                <label class="form-check-label fw-semibold" for="modIsActive">Правило активно</label>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary px-4">
            <i class="bi bi-check-lg"></i> Сохранить
        </button>
        <a href="{{ route('admin.departments.show', $department) }}" class="btn btn-outline-secondary">
            Отмена
        </a>
    </div>
</form>
