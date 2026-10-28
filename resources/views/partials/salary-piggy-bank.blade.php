{{-- Копилка и выдача зарплаты (App\Services\SalaryService::summary()).
     Параметры: $worker, $salary. Не зависит от периода на странице — считается по невыданному. --}}
@php
    $rub      = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' ₽';
    $isAdmin  = auth()->user()->isAdmin();
    $canEdit  = $salary['isOpen'] && ($isAdmin || $salary['accrued'] > 0);
    $toBank   = $salary['toBank'];
    // Остаток копилки работник может скрыть от себя (workers.hide_piggy_balance); админ видит всегда.
    // Скрываются и проводки копилки в истории: их сумма и есть остаток.
    $isSelf      = auth()->user()->worker_id === $worker->id;
    $hideBalance = $isSelf && $worker->hide_piggy_balance;
    $money       = fn ($v) => $hideBalance ? '***' : $rub($v);
    $balanceCls  = fn ($v) => $hideBalance ? 'text-muted' : ($v < 0 ? 'text-danger' : 'text-success');
    $step        = $salary['requestStep'];
@endphp

<div class="card border-0 shadow-sm mb-3" id="salary">
    {{-- Шапка видна и в свёрнутом блоке: статус недели и три главные цифры --}}
    <div class="card-header bg-white py-2" style="cursor:pointer" id="salary-toggle" role="button">
        <div class="d-flex justify-content-between align-items-center gap-2">
            <span class="fw-semibold text-nowrap"><i class="bi bi-piggy-bank me-1"></i>Копилка и выдача</span>
            <span class="d-flex align-items-center gap-2">
                @if($salary['isOpen'])
                    <span class="badge rounded-pill bg-success-subtle text-success-emphasis">Можно менять</span>
                @else
                    <span class="badge rounded-pill bg-warning-subtle text-warning-emphasis">
                        <i class="bi bi-clock me-1"></i>Выбор с {{ $salary['nextCutoff']->translatedFormat('D H:i') }}
                    </span>
                @endif
                <i class="bi bi-chevron-down" id="salary-chevron"></i>
            </span>
        </div>
        <div class="row g-2 mt-0">
            <div class="col-4">
                <div class="text-muted small">Начислено</div>
                <div class="fw-bold text-nowrap">{{ $rub($salary['accrued']) }}</div>
            </div>
            <div class="col-4">
                <div class="text-muted small text-nowrap">
                    {{-- Пока неделя идёт, сумма к выдаче ещё растёт с приёмками --}}
                    К выдаче{{ $salary['isOpen'] ? '' : ' ≈' }}
                    @if($salary['isRequested'])
                        <i class="bi bi-pencil-fill text-info" title="Изменено вручную"></i>
                    @endif
                </div>
                <div class="fw-bold text-success text-nowrap">{{ $rub($salary['toPay']) }}</div>
            </div>
            <div class="col-4">
                <div class="text-muted small d-flex align-items-center gap-1">
                    Копилка
                    @if($isSelf)
                        {{-- Клик по глазу не должен сворачивать блок --}}
                        <form method="POST" action="{{ route('salary.hide-balance', $worker) }}" data-submit-guard
                              class="d-inline" onclick="event.stopPropagation()">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="hide" value="{{ $hideBalance ? 0 : 1 }}">
                            <button type="submit" class="btn btn-sm btn-link text-muted p-0 lh-1"
                                    title="{{ $hideBalance ? 'Показать остаток копилки' : 'Скрыть остаток копилки' }}">
                                <i class="bi {{ $hideBalance ? 'bi-eye-slash' : 'bi-eye' }}"></i>
                            </button>
                        </form>
                    @endif
                </div>
                <div class="fw-bold text-nowrap {{ ! $hideBalance && $salary['balance'] < 0 ? 'text-danger' : '' }}">
                    {{ $money($salary['balance']) }}
                </div>
            </div>
        </div>
    </div>
    <div class="card-body py-2 px-3" id="salary-collapse" style="display:none"
         data-force-open="{{ $errors->hasAny(['amount', 'paid_cash', 'paid_card', 'paid_tax', 'paid', 'piggy_amount', 'piggy_comment']) ? 1 : 0 }}">

        <div class="d-flex justify-content-between align-items-center small text-muted mb-2">
            <span>
                @if($salary['isOpen'])
                    Неделя {{ $salary['periodFrom']->format('d.m') }} — {{ $salary['periodTo']->copy()->subSecond()->format('d.m') }}.
                    Сумму к выдаче можно менять, пока зарплату не выдали.
                @else
                    Неделя с {{ $salary['periodFrom']->format('d.m') }}: начисленное растёт с приёмками до конца четверга.
                    Выбрать сумму — с {{ $salary['nextCutoff']->translatedFormat('D d.m H:i') }}.
                @endif
            </span>
            <button type="button" class="btn btn-sm btn-link text-muted p-0" title="Как это работает"
                    data-bs-toggle="collapse" data-bs-target="#salary-help">
                <i class="bi bi-question-circle"></i>
            </button>
        </div>

        <div class="collapse" id="salary-help">
            <div class="small text-muted bg-light rounded p-2 mb-2">
                <div>Неделя — с пятницы 00:00 по четверг 23:59. Сумму к выдаче можно менять с четверга {{ config('salary.cutoff_time') }} и до выдачи.</div>
                <div>По умолчанию к выдаче — начисленное минус {{ config('salary.piggy_percent') }}%, до тысячи; остаток — в копилку.</div>
                <div>«Хочу получить» — с шагом {{ $salary['requestStep'] }} ₽@unless($isAdmin), не больше {{ $money($salary['maxRequest']) }}@endunless.
                    Меньше начисленного — остаток уйдёт в копилку, больше — возьмётся из копилки.</div>
                <div>Начисленное до выдачи ещё может измениться (приёмки, пересчёт коэффициентов) — сумма к выдаче
                    останется прежней, разница пройдёт через копилку. Пересчёт уже выданных недель уходит в копилку
                    при следующей выдаче.</div>
                @if($isAdmin)
                    <div>Налог входит в выданное. Начисленное − выданное уходит в копилку.</div>
                @endif
            </div>
        </div>

        <div class="row g-2 mb-2">
            <div class="col-6">
                <div class="text-muted small">{{ $toBank < 0 ? 'Из копилки' : 'В копилку' }}</div>
                <div class="fw-semibold {{ $toBank < 0 ? 'text-danger' : 'text-success' }}">{{ $rub(abs($toBank)) }}</div>
            </div>
            <div class="col-6">
                <div class="text-muted small">Копилка после выдачи</div>
                <div class="fw-semibold {{ ! $hideBalance && $salary['balanceAfter'] < 0 ? 'text-danger' : '' }}">
                    {{ $money($salary['balanceAfter']) }}
                </div>
            </div>
        </div>

        @if(round($salary['recalc']) != 0)
            <div class="small mb-2 {{ $salary['recalc'] < 0 ? 'text-danger' : 'text-success' }}">
                <i class="bi bi-arrow-repeat me-1"></i>Перерасчёт выданных недель:
                {{ $salary['recalc'] > 0 ? '+' : '' }}{{ $rub($salary['recalc']) }} — при выдаче уйдёт в копилку
            </div>
        @endif

        @if($canEdit)
            <form method="POST" action="{{ route('salary.request', $worker) }}" data-submit-guard class="border-top pt-2">
                @csrf
                @method('PATCH')
                <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                    <label class="small text-muted mb-0">Хочу получить</label>
                    <div class="input-group input-group-sm" style="max-width:12rem">
                        <button type="button" class="btn btn-outline-secondary" data-salary-step-btn="-1"
                                title="−{{ $step }} ₽"><i class="bi bi-dash-lg"></i></button>
                        <input type="number" name="amount" min="0" step="{{ $step }}" data-salary-step="{{ $step }}"
                               @unless($isAdmin) max="{{ $salary['maxRequest'] }}" @endunless
                               value="{{ old('amount', round($salary['toPay'] / $step) * $step) }}"
                               class="form-control text-center fw-semibold @error('amount') is-invalid @enderror">
                        <button type="button" class="btn btn-outline-secondary" data-salary-step-btn="1"
                                title="+{{ $step }} ₽"><i class="bi bi-plus-lg"></i></button>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button type="{{ $salary['isRequested'] ? 'submit' : 'button' }}" name="reset" value="1"
                            class="btn btn-sm btn-outline-secondary flex-fill @unless($salary['isRequested']) d-none @endunless"
                            data-salary-default="{{ round($salary['defaultToPay'] / $step) * $step }}"
                            data-salary-saved="{{ $salary['isRequested'] ? 1 : 0 }}">
                        По умолчанию
                    </button>
                    <button type="submit" class="btn btn-sm btn-primary flex-fill">Сохранить</button>
                </div>
                @error('amount') <div class="small text-danger mt-1">{{ $message }}</div> @enderror
            </form>
        @endif

        @if($isAdmin && $salary['isOpen'])
            <form method="POST" action="{{ route('salary.paid', $worker) }}" data-submit-guard data-payout-form
                  data-to-pay="{{ $salary['toPay'] }}"
                  onsubmit="return confirm('Отметить зарплату выданной? Период будет закрыт.')"
                  class="bg-light rounded p-2 mt-2">
                @csrf
                <div class="fw-semibold small mb-2"><i class="bi bi-cash-coin me-1"></i>Выдать зарплату</div>
                @foreach(['paid_cash' => 'Наличными', 'paid_card' => 'На карту', 'paid_tax' => 'Налог'] as $field => $label)
                    <div class="d-flex justify-content-between align-items-center gap-2 mb-1">
                        <label class="small mb-0" for="payout-{{ $field }}">{{ $label }}</label>
                        <div class="d-flex align-items-center gap-1">
                            @unless($field === 'paid_tax')
                                <button type="button" class="btn btn-sm btn-link p-0 small text-decoration-none"
                                        data-payout-fill="{{ $field }}" title="Всю сумму — {{ mb_strtolower($label) }}">всё</button>
                            @endunless
                            <div class="input-group input-group-sm" style="width:9rem">
                                <input type="number" id="payout-{{ $field }}" name="{{ $field }}" min="0" step="0.01" placeholder="0"
                                       data-payout-part
                                       value="{{ old($field, $field === 'paid_cash' ? round($salary['toPay'], 2) : '') }}"
                                       class="form-control text-end @error($field) is-invalid @enderror">
                                <span class="input-group-text">₽</span>
                            </div>
                        </div>
                    </div>
                @endforeach
                <div class="d-flex justify-content-between small border-top pt-1 mt-1">
                    <span class="text-muted">Итого выдаётся</span>
                    <span data-payout-check></span>
                </div>
                <input type="text" name="comment" maxlength="255" value="{{ old('comment') }}"
                       class="form-control form-control-sm mt-2" placeholder="Комментарий (необязательно)">
                <button type="submit" class="btn btn-sm btn-success w-100 mt-2">
                    <i class="bi bi-check2 me-1"></i>Зарплата выдана
                </button>
                @foreach(['paid_cash', 'paid_card', 'paid_tax', 'paid'] as $field)
                    @error($field) <div class="small text-danger">{{ $message }}</div> @enderror
                @endforeach
            </form>
        @endif

        @if($isAdmin)
            <details class="border-top pt-2 mt-2" @if($errors->hasAny(['piggy_amount', 'piggy_comment'])) open @endif>
                <summary class="small text-muted" style="cursor:pointer">Корректировка копилки (аванс, долг, остаток)</summary>
                <form method="POST" action="{{ route('salary.piggy-adjust', $worker) }}" data-submit-guard
                      class="d-flex flex-wrap gap-2 align-items-end mt-2">
                    @csrf
                    <div>
                        <label class="form-label small text-muted mb-1">Сумма, ₽ (− аванс/долг)</label>
                        <input type="number" name="piggy_amount" step="0.01" required value="{{ old('piggy_amount') }}"
                               class="form-control form-control-sm @error('piggy_amount') is-invalid @enderror"
                               style="width:9rem;border-radius:.4rem">
                    </div>
                    <div class="flex-grow-1" style="min-width:10rem">
                        <label class="form-label small text-muted mb-1">Комментарий</label>
                        <input type="text" name="piggy_comment" maxlength="255" required value="{{ old('piggy_comment') }}"
                               class="form-control form-control-sm @error('piggy_comment') is-invalid @enderror"
                               style="border-radius:.4rem">
                    </div>
                    <button type="submit" class="btn btn-sm btn-outline-primary">Провести</button>
                    @foreach(['piggy_amount', 'piggy_comment'] as $field)
                        @error($field) <div class="small text-danger w-100">{{ $message }}</div> @enderror
                    @endforeach
                </form>
            </details>
        @endif

        @if($salary['payouts']->isNotEmpty() || $salary['adjustments']->isNotEmpty())
            <details class="border-top pt-2 mt-2">
                <summary class="small text-muted" style="cursor:pointer">История выдач и копилки</summary>

                @if($salary['payouts']->isNotEmpty())
                    {{-- Десктоп --}}
                    <div class="d-none d-md-block mt-2">
                        <table class="table table-sm small mb-2">
                            <thead>
                                <tr class="text-muted">
                                    <th>Период</th>
                                    <th class="text-end">Начислено</th>
                                    <th class="text-end">Выдано</th>
                                    <th>Из них</th>
                                    <th class="text-end">Копилка</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($salary['payouts'] as $payout)
                                    <tr>
                                        <td>
                                            {{ $payout->period_from->format('d.m') }} — {{ $payout->period_to->format('d.m') }}
                                            <div class="text-muted">выдано {{ $payout->paid_at->format('d.m.Y') }}</div>
                                        </td>
                                        <td class="text-end">
                                            {{ $rub($payout->accrued) }}
                                            @if($payout->recalc)
                                                <div class="text-muted">перерасчёт {{ $payout->recalc > 0 ? '+' : '' }}{{ $rub($payout->recalc) }}</div>
                                            @endif
                                        </td>
                                        <td class="text-end fw-semibold">{{ $rub($payout->paid_total) }}</td>
                                        <td>
                                            @include('partials.salary-payout-split', ['payout' => $payout])
                                        </td>
                                        <td class="text-end {{ ! $hideBalance && $payout->to_bank < 0 ? 'text-danger' : '' }}">
                                            {{ ! $hideBalance && $payout->to_bank > 0 ? '+' : '' }}{{ $money($payout->to_bank) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    {{-- Мобильный --}}
                    <div class="d-md-none mt-2">
                        @foreach($salary['payouts'] as $payout)
                            <div class="border rounded p-2 mb-2 small">
                                <div class="d-flex justify-content-between">
                                    <span>{{ $payout->period_from->format('d.m') }} — {{ $payout->period_to->format('d.m') }}</span>
                                    <span class="fw-semibold">{{ $rub($payout->paid_total) }}</span>
                                </div>
                                <div class="text-muted">
                                    начислено {{ $rub($payout->accrued) }}
                                    @if($payout->recalc)
                                        · перерасчёт {{ $payout->recalc > 0 ? '+' : '' }}{{ $rub($payout->recalc) }}
                                    @endif
                                </div>
                                @include('partials.salary-payout-split', ['payout' => $payout])
                                <div class="{{ ! $hideBalance && $payout->to_bank < 0 ? 'text-danger' : 'text-muted' }}">
                                    копилка {{ ! $hideBalance && $payout->to_bank > 0 ? '+' : '' }}{{ $money($payout->to_bank) }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if($salary['adjustments']->isNotEmpty())
                    <div class="small fw-semibold mt-2 mb-1">Корректировки копилки</div>
                    @foreach($salary['adjustments'] as $entry)
                        <div class="d-flex justify-content-between small border-bottom py-1 gap-2">
                            <span>
                                {{ $entry->created_at->format('d.m.Y') }} · {{ $entry->comment }}
                            </span>
                            <span class="text-nowrap {{ $balanceCls($entry->amount) }}">
                                {{ ! $hideBalance && $entry->amount > 0 ? '+' : '' }}{{ $money($entry->amount) }}
                            </span>
                        </div>
                    @endforeach
                @endif
            </details>
        @endif
    </div>
</div>

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // ── Сворачиваемый блок копилки: по умолчанию свёрнут, ошибки формы раскрывают ──
            const STORAGE_KEY = 'dashboard_salary_open';
            const toggle   = document.getElementById('salary-toggle');
            const collapse = document.getElementById('salary-collapse');
            const chevron  = document.getElementById('salary-chevron');
            if (!toggle) return;

            function applyState(open) {
                collapse.style.display = open ? '' : 'none';
                chevron.className = open ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
            }

            let saved = null;
            try { saved = localStorage.getItem(STORAGE_KEY); } catch (e) {}
            applyState(collapse.dataset.forceOpen === '1' || saved === 'open');

            toggle.addEventListener('click', function () {
                const isHidden = collapse.style.display === 'none';
                applyState(isHidden);
                try { localStorage.setItem(STORAGE_KEY, isHidden ? 'open' : 'closed'); } catch (e) {}
            });

            // ── Выдача (админ): «итого выдаётся» против суммы к выдаче, кнопки «всё наличными / на карту» ──
            document.querySelectorAll('[data-payout-form]').forEach(function (form) {
                const toPay = Number(form.dataset.toPay);
                const parts = form.querySelectorAll('[data-payout-part]');
                const check = form.querySelector('[data-payout-check]');
                const fmt   = v => Math.round(v).toLocaleString('ru-RU') + ' ₽';

                function update() {
                    if (!check) return;
                    const total = Array.from(parts).reduce((s, el) => s + (Number(el.value) || 0), 0);
                    const diff  = total - toPay;
                    check.className = Math.round(diff) === 0 ? 'small text-success' : 'small text-danger';
                    check.textContent = fmt(total) + (Math.round(diff) === 0 ? ' ✓'
                        : (diff > 0 ? ' — на ' + fmt(diff) + ' больше, возьмётся из копилки'
                                    : ' — на ' + fmt(-diff) + ' меньше, уйдёт в копилку'));
                }

                form.querySelectorAll('[data-payout-fill]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        parts.forEach(el => { el.value = el.name === btn.dataset.payoutFill ? toPay : ''; });
                        update();
                    });
                });
                parts.forEach(el => el.addEventListener('input', update));
                update();
            });

            // ── «Хочу получить»: ручной ввод — к ближайшему кратному шагу в пределах min/max,
            //    кнопки −/+ — на шаг от округлённого значения ──
            document.querySelectorAll('[data-salary-step]').forEach(function (input) {
                const step = Number(input.dataset.salaryStep);

                function snap(value) {
                    let v = Math.round(value / step) * step;
                    if (input.max !== '') v = Math.min(v, Number(input.max));
                    return Math.max(0, v);
                }

                // «По умолчанию»: сохранённая сумма — кнопка видна всегда, иначе — когда сумма отличается
                const resetBtn = input.form.querySelector('[data-salary-default]');
                const saved    = resetBtn && resetBtn.dataset.salarySaved === '1';
                function syncReset() {
                    if (!resetBtn || saved) return;
                    resetBtn.classList.toggle('d-none', Number(input.value) === Number(resetBtn.dataset.salaryDefault));
                }
                if (resetBtn && !saved) {
                    resetBtn.addEventListener('click', function () {
                        input.value = resetBtn.dataset.salaryDefault;
                        syncReset();
                    });
                }

                input.addEventListener('change', function () {
                    if (input.value !== '') input.value = snap(Number(input.value));
                    syncReset();
                });

                input.closest('.input-group').querySelectorAll('[data-salary-step-btn]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        const current = snap(Number(input.value) || 0);
                        input.value = snap(current + Number(btn.dataset.salaryStepBtn) * step);
                        syncReset();
                    });
                });
                syncReset();
            });
        });
    </script>
@endpush
