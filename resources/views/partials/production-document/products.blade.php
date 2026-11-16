{{--
    Карточка «Продукция» на show приёмки и цеха: позиции с коэффициентом, ставкой и зарплатой
    пильщика и мастера, кнопки «Обновить коэф.» / «Коэффициенты» и панель правки коэффициентов.
    Один блок на оба документа — правится здесь, а не в двух шаблонах. Скрипт панели —
    partials/production-document/coeff-edit-script.

    Параметры:
      $document       — StoneReception или Workshop (политика modify, effectiveDepartmentId())
      $items          — позиции продукции
      $scope          — область правил: 'reception' | 'workshop'
      $batchSku       — SKU партии сырья (доступность ручных правил), у цеха null
      $refreshRoute   — POST «Обновить коэф.»
      $updateRoute    — POST сохранения правки коэффициентов
      $title, $icon   — заголовок карточки и иконка bootstrap-icons
      $emptyText      — текст, когда позиций нет
      $qtyFormat      — fn($qty, $uom): string, по умолчанию Uom::format (у цеха — до 4 знаков)
      $yieldText      — необязательная строка под итогом (у приёмки — коэф. выхода)
--}}
@php
    $items       = $items->values();
    $batchSku    = $batchSku ?? null;
    $qtyFormat   = $qtyFormat ?? fn ($qty, $uom) => \App\Support\Uom::format($qty, $uom);
    $yieldText   = $yieldText ?? null;
    $canModify   = auth()->user()->can('modify', $document);
@endphp
<div class="card shadow-sm mb-3">
    <div style="background:#f8f9fa;padding:.3rem .5rem;border-bottom:1px solid #dee2e6;border-radius:.35rem .35rem 0 0;display:flex;justify-content:space-between;align-items:center">
        <span class="small fw-semibold"><i class="bi {{ $icon }} me-1"></i> {{ $title }}</span>
        @if($items->isNotEmpty() && $canModify)
            <div class="d-flex gap-1">
                <form data-submit-guard method="POST"
                      action="{{ $refreshRoute }}"
                      data-confirm="Обновить коэффициенты из справочника товаров?&#10;Значения effective_cost_coeff будут пересчитаны по текущим prod_cost_coeff.">
                    @csrf
                    <button type="submit"
                            class="btn btn-sm btn-outline-secondary"
                            title="Обновить коэффициенты из справочника товаров">
                        <i class="bi bi-arrow-repeat"></i>
                        <span class="d-none d-sm-inline ms-1">Обновить коэф.</span>
                    </button>
                </form>
                <button type="button"
                        class="btn btn-sm btn-outline-warning"
                        id="toggleCoeffEdit"
                        title="Редактировать коэффициенты">
                    <i class="bi bi-pencil-square"></i>
                    <span class="d-none d-sm-inline ms-1">Коэффициенты</span>
                </button>
            </div>
        @endif
    </div>

    @if($items->isNotEmpty())
        <div class="table-responsive">
            <table class="table mb-0" style="font-size:.75rem;table-layout:auto">
                <colgroup>
                    <col>
                    <col style="width:1%">
                    <col style="width:1%">
                    <col style="width:1%">
                    <col style="width:1%">
                    <col style="width:1%">
                </colgroup>
                <thead class="table-light">
                <tr>
                    <th style="border-left:4px solid transparent;padding:.3rem .1rem .3rem .4rem">Продукт</th>
                    <th class="text-end text-nowrap" style="padding:.3rem .25rem .3rem .1rem">Кол-во</th>
                    <th style="padding:.3rem .1rem"></th>
                    <th class="text-end text-nowrap" style="padding:.3rem .25rem" title="Коэффициент зафиксирован при создании">Коэф.</th>
                    <th class="text-end text-nowrap" style="padding:.3rem .25rem">Ставка</th>
                    <th class="text-end text-nowrap" style="border-right:4px solid transparent;padding:.3rem .4rem .3rem .25rem">Зарплата</th>
                </tr>
                </thead>
                <tbody>
                @foreach($items as $item)
                    @php
                        $skuColor = \App\Models\Product::getColorBySku($item->product?->sku);
                        $skuBg    = $skuColor === '#FFFFFF' ? '' : 'background:' . $skuColor . '18;';
                    @endphp
                    <tr>
                        <td style="border-left:4px solid {{ $skuColor }};{{ $skuBg }};word-break:break-word;padding:.3rem .1rem .3rem .4rem">
                            @if($item->product)
                                <a href="{{ route('products.show', $item->product->moysklad_id) }}"
                                   class="text-body text-decoration-none">{{ $item->product->name }}</a>
                            @else
                                <span class="text-danger">Продукт не найден</span>
                            @endif
                            @include('partials.modifier-badges', [
                                'modifiers' => $item->modifiers,
                                'class'     => 'ms-1',
                                'font'      => '.6rem',
                            ])
                        </td>
                        <td class="text-end text-nowrap" style="{{ $skuBg }};padding:.3rem .25rem .3rem .1rem">
                            {{ $qtyFormat($item->quantity, $item->product?->uom) }}
                        </td>
                        {{-- Метка роли: верхняя строка — пильщик, нижняя — мастер --}}
                        <td class="text-center text-muted" style="{{ $skuBg }};padding:.3rem .1rem">
                            <div style="font-size:.65rem;line-height:1.5" title="Пильщик"><i class="bi bi-person"></i></div>
                            <div style="font-size:.65rem;line-height:1.5" title="Мастер"><i class="bi bi-person-gear"></i></div>
                        </td>
                        <td class="text-end text-nowrap text-muted" style="{{ $skuBg }};padding:.3rem .25rem">
                            <div style="font-size:.65rem;line-height:1.5">
                                @if($item->effective_cost_coeff !== null)
                                    ×{{ \App\Support\RateFormula::formatCoeff($item->effective_cost_coeff) }}
                                @else
                                    —
                                @endif
                            </div>
                            <div style="font-size:.65rem;line-height:1.5">
                                @if($item->master_effective_cost_coeff !== null)
                                    ×{{ \App\Support\RateFormula::formatCoeff($item->master_effective_cost_coeff) }}
                                @else
                                    —
                                @endif
                            </div>
                        </td>
                        <td class="text-end text-nowrap text-muted" style="{{ $skuBg }};padding:.3rem .25rem">
                            <div style="font-size:.65rem;line-height:1.5">
                                @if($item->worker_cost_per_m2 !== null)
                                    {{ number_format($item->worker_cost_per_m2, 0, '.', ' ') }} {{ \App\Support\Uom::rate($item->product?->uom) }}
                                @else
                                    —
                                @endif
                            </div>
                            <div style="font-size:.65rem;line-height:1.5">
                                @if($item->master_cost_per_m2 !== null)
                                    {{ number_format($item->master_cost_per_m2, 0, '.', ' ') }} {{ \App\Support\Uom::rate($item->product?->uom) }}
                                @else
                                    —
                                @endif
                            </div>
                        </td>
                        <td class="text-end text-nowrap" style="border-right:4px solid {{ $skuColor }};{{ $skuBg }};padding:.3rem .4rem .3rem .25rem">
                            <div class="fw-semibold text-success" style="font-size:.65rem;line-height:1.5">
                                @if($item->worker_cost_per_m2 !== null)
                                    {{ number_format($item->calculateWorkerPay(), 0, '.', ' ') }} ₽
                                @else
                                    —
                                @endif
                            </div>
                            <div class="text-muted" style="font-size:.65rem;line-height:1.5">
                                @if($item->master_cost_per_m2 !== null)
                                    {{ number_format($item->calculateMasterPay(), 0, '.', ' ') }} ₽
                                @else
                                    —
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot class="table-light">
                <tr>
                    <th class="fw-bold" style="padding:.3rem .1rem .3rem .4rem">ИТОГО:</th>
                    <th class="text-end text-nowrap fw-semibold" style="font-size:.9rem;padding:.3rem .25rem .3rem .1rem">
                        {{-- Раздельно по единицам, как Uom::formatTotals --}}
                        {{ collect(\App\Support\Uom::itemTotals($items))->map(fn ($sum, $unit) => $qtyFormat($sum, (string) $unit))->implode(' · ') }}
                    </th>
                    <th class="text-center text-muted fw-normal" style="padding:.3rem .1rem">
                        <div style="font-size:.75rem;line-height:1.5" title="Пильщик"><i class="bi bi-person"></i></div>
                        <div style="font-size:.75rem;line-height:1.5" title="Мастер"><i class="bi bi-person-gear"></i></div>
                    </th>
                    <th></th>
                    <th></th>
                    <th class="text-end text-nowrap" style="padding:.3rem .4rem .3rem .25rem">
                        <div class="text-success" style="font-size:.75rem;line-height:1.5">
                            {{ number_format($items->sum(fn($i) => $i->calculateWorkerPay()), 0, '.', ' ') }} ₽
                        </div>
                        <div class="text-muted" style="font-size:.75rem;line-height:1.5">
                            {{ number_format($items->sum(fn($i) => $i->calculateMasterPay()), 0, '.', ' ') }} ₽
                        </div>
                    </th>
                </tr>
                @if($yieldText)
                    <tr>
                        <th colspan="6" class="text-muted fw-normal" style="padding:.3rem .4rem">{{ $yieldText }}</th>
                    </tr>
                @endif
                </tfoot>
            </table>
        </div>

        {{-- Форма редактирования коэффициентов (скрыта по умолчанию) --}}
        @if($canModify)
        <div id="coeffEditPanel" style="display:none" class="p-2 border-top bg-warning bg-opacity-10">
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                <span class="small text-warning-emphasis fw-semibold">
                    Только для исправления ошибочно заданных значений.
                </span>
            </div>
            <form data-submit-guard method="POST"
                  action="{{ $updateRoute }}"
                  id="coeffEditForm">
                @csrf
                <div style="overflow-x:auto">
                    <table class="table table-sm mb-2" style="min-width:440px">
                        <thead class="table-light">
                        <tr>
                            <th>Продукт</th>
                            <th style="width:1%"></th>
                            <th class="text-center" style="width:130px">Базовый коэф.</th>
                            <th class="text-center" style="width:180px">Правила</th>
                            <th class="text-end" style="width:100px">Итог</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($items as $i => $item)
                            <tr>
                                <td class="small">{{ $item->product->name ?? '—' }}</td>
                                {{-- Метка роли: верхнее поле — пильщик, нижнее — мастер --}}
                                <td class="text-muted">
                                    <div class="d-flex align-items-center" style="height:calc(1.5em + .5rem + 2px)" title="Пильщик"><i class="bi bi-person"></i></div>
                                    <div class="d-flex align-items-center mt-1" style="height:calc(1.5em + .5rem + 2px)" title="Мастер"><i class="bi bi-person-gear"></i></div>
                                </td>
                                <td class="text-center">
                                    <input type="hidden" name="items[{{ $i }}][item_id]" value="{{ $item->id }}">
                                    <input type="number"
                                           name="items[{{ $i }}][base_coeff]"
                                           class="form-control form-control-sm text-center coeff-base-input"
                                           step="0.0001" min="-100" max="100"
                                           value="{{ number_format($item->base_coeff, 4, '.', '') }}"
                                           data-row="{{ $i }}"
                                           title="Пильщик">
                                    <input type="number"
                                           name="items[{{ $i }}][master_base_coeff]"
                                           class="form-control form-control-sm text-center coeff-master-base-input mt-1"
                                           step="0.0001" min="-100" max="100"
                                           value="{{ number_format($item->master_base_cost_coeff ?? $item->product?->master_cost_coeff ?? 0, 4, '.', '') }}"
                                           data-row="{{ $i }}"
                                           title="Мастер">
                                </td>
                                <td>
                                    <div class="modifier-picker d-flex align-items-center gap-2 flex-wrap"
                                         data-row="{{ $i }}"
                                         data-tpl-index="{{ $i }}"
                                         data-scope="{{ $scope }}"
                                         data-department-id="{{ $document->effectiveDepartmentId() }}"
                                         data-batch-sku="{{ $batchSku }}"
                                         data-sku="{{ $item->product?->sku }}"
                                         data-input-name="items[{{ $i }}]"
                                         data-active-keys="{{ $item->modifiers->pluck('key')->implode(',') }}"
                                         data-editable-auto="1"
                                         data-disabled-keys="{{ implode(',', $item->disabled_modifier_keys ?? []) }}"></div>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex align-items-center justify-content-end" style="height:calc(1.5em + .5rem + 2px)">
                                        <span class="badge bg-secondary coeff-result-display" data-row="{{ $i }}" title="Пильщик">
                                            {{ number_format($item->effective_cost_coeff ?? 0, 4) }}
                                        </span>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-end mt-1" style="height:calc(1.5em + .5rem + 2px)">
                                        <span class="badge bg-secondary coeff-master-result-display" data-row="{{ $i }}" title="Мастер">
                                            {{ number_format($item->master_effective_cost_coeff ?? 0, 4) }}
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-warning">
                        <i class="bi bi-save"></i> Сохранить
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="cancelCoeffEdit">
                        Отмена
                    </button>
                </div>
            </form>
        </div>
        @endif
    @else
        <div class="text-center py-4">
            <i class="bi {{ $icon }} fs-3 text-muted d-block mb-1"></i>
            <p class="text-muted small mb-0">{{ $emptyText }}</p>
        </div>
    @endif
</div>
