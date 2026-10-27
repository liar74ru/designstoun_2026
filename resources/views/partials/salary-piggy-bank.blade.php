{{-- Копилка и выдача зарплаты (App\Services\SalaryService::summary()).
     Параметры: $worker, $salary. Не зависит от периода на странице — считается по невыданному. --}}
@php
    $rub      = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' ₽';
    $isAdmin  = auth()->user()->isAdmin();
    $canEdit  = $salary['isOpen'] && ($isAdmin || $salary['accrued'] > 0);
    $toBank   = $salary['toBank'];
@endphp

<div class="card border-0 shadow-sm mb-3" id="salary">
    <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center gap-2"
         style="cursor:pointer" id="salary-toggle" role="button">
        <span class="fw-semibold"><i class="bi bi-piggy-bank me-1"></i>Копилка и выдача</span>
        <span class="d-flex align-items-center gap-2">
            <span class="fw-bold {{ $salary['balance'] < 0 ? 'text-danger' : 'text-success' }}">{{ $rub($salary['balance']) }}</span>
            <i class="bi bi-chevron-down" id="salary-chevron"></i>
        </span>
    </div>
    <div class="card-body py-2 px-3" id="salary-collapse" style="display:none"
         data-force-open="{{ $errors->hasAny(['amount', 'paid_cash', 'paid_card', 'paid_tax', 'paid', 'piggy_amount', 'piggy_comment']) ? 1 : 0 }}">

        <div class="d-flex justify-content-between align-items-center small text-muted mb-2">
            <span>
                @if($salary['isOpen'])
                    Неделя {{ $salary['periodFrom']->format('d.m') }} — {{ $salary['periodTo']->copy()->subSecond()->format('d.m') }}
                @else
                    Неделя с {{ $salary['periodFrom']->format('d.m') }} · выплата — с {{ $salary['nextCutoff']->translatedFormat('D d.m H:i') }}
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
                <div>«Хочу получить» — с шагом {{ $salary['requestStep'] }} ₽@unless($isAdmin), не больше {{ $rub($salary['maxRequest']) }}@endunless.
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
            <div class="col-6 col-md-3">
                <div class="text-muted small">Начислено</div>
                <div class="fw-semibold">{{ $rub($salary['accrued']) }}</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted small">{{ $toBank < 0 ? 'Из копилки' : 'В копилку' }}</div>
                <div class="fw-semibold {{ $toBank < 0 ? 'text-danger' : 'text-success' }}">{{ $rub(abs($toBank)) }}</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted small">
                    К выдаче
                    @if($salary['isRequested'])
                        <span class="badge bg-info-subtle text-info-emphasis" title="Изменено вручную">изменено</span>
                    @endif
                </div>
                <div class="fs-5 fw-bold text-success">{{ $rub($salary['toPay']) }}</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted small">Копилка</div>
                <div class="fw-semibold {{ $salary['balance'] < 0 ? 'text-danger' : '' }}">
                    {{ $rub($salary['balance']) }}
                </div>
                @if($salary['isOpen'] && round($salary['balanceAfter']) != round($salary['balance']))
                    <div class="small text-muted">после выдачи {{ $rub($salary['balanceAfter']) }}</div>
                @endif
            </div>
        </div>

        @if(round($salary['recalc']) != 0)
            <div class="small mb-2 {{ $salary['recalc'] < 0 ? 'text-danger' : 'text-success' }}">
                <i class="bi bi-arrow-repeat me-1"></i>Перерасчёт выданных недель:
                {{ $salary['recalc'] > 0 ? '+' : '' }}{{ $rub($salary['recalc']) }} — при выдаче уйдёт в копилку
            </div>
        @endif

        @if($canEdit)
            @php $step = $salary['requestStep']; @endphp
            <form method="POST" action="{{ route('salary.request', $worker) }}" data-submit-guard
                  class="d-flex flex-wrap gap-2 align-items-end border-top pt-2">
                @csrf
                @method('PATCH')
                <div>
                    <label class="form-label small text-muted mb-1">Хочу получить, ₽</label>
                    {{-- Ввод вручную округляется до ближайшего кратного шагу, кнопки −/+ шагают на него же --}}
                    <div class="input-group input-group-sm" style="width:12rem">
                        <button type="button" class="btn btn-outline-secondary" data-salary-step-btn="-1"
                                title="−{{ $step }} ₽"><i class="bi bi-dash-lg"></i></button>
                        <input type="number" name="amount" min="0" step="{{ $step }}" data-salary-step="{{ $step }}"
                               @unless($isAdmin) max="{{ $salary['maxRequest'] }}" @endunless
                               value="{{ old('amount', round($salary['toPay'] / $step) * $step) }}"
                               class="form-control text-center @error('amount') is-invalid @enderror">
                        <button type="button" class="btn btn-outline-secondary" data-salary-step-btn="1"
                                title="+{{ $step }} ₽"><i class="bi bi-plus-lg"></i></button>
                    </div>
                </div>
                <button type="submit" class="btn btn-sm btn-primary">Сохранить</button>
                @if($salary['isRequested'])
                    <button type="submit" name="reset" value="1" class="btn btn-sm btn-outline-secondary">
                        По умолчанию
                    </button>
                @endif
                @error('amount')
                    <div class="small text-danger w-100">{{ $message }}</div>
                @enderror
            </form>
        @endif

        @if($isAdmin && $salary['isOpen'])
            <form method="POST" action="{{ route('salary.paid', $worker) }}" data-submit-guard
                  onsubmit="return confirm('Отметить зарплату выданной? Период будет закрыт.')"
                  class="border-top pt-2 mt-2">
                @csrf
                <div class="d-flex flex-wrap gap-2 align-items-end">
                    @foreach(['paid_cash' => 'Наличные', 'paid_card' => 'На карту', 'paid_tax' => 'Налог'] as $field => $label)
                        <div>
                            <label class="form-label small text-muted mb-1">{{ $label }}, ₽</label>
                            <input type="number" name="{{ $field }}" min="0" step="0.01"
                                   value="{{ old($field, $field === 'paid_cash' ? round($salary['toPay'], 2) : '') }}"
                                   class="form-control form-control-sm @error($field) is-invalid @enderror"
                                   style="width:8rem;border-radius:.4rem">
                        </div>
                    @endforeach
                    <div class="flex-grow-1" style="min-width:10rem">
                        <label class="form-label small text-muted mb-1">Комментарий</label>
                        <input type="text" name="comment" maxlength="255" value="{{ old('comment') }}"
                               class="form-control form-control-sm" style="border-radius:.4rem">
                    </div>
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="bi bi-cash-coin me-1"></i>Зарплата выдана
                    </button>
                </div>
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
                                        <td class="text-end {{ $payout->to_bank < 0 ? 'text-danger' : '' }}">
                                            {{ $payout->to_bank > 0 ? '+' : '' }}{{ $rub($payout->to_bank) }}
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
                                <div class="{{ $payout->to_bank < 0 ? 'text-danger' : 'text-muted' }}">
                                    копилка {{ $payout->to_bank > 0 ? '+' : '' }}{{ $rub($payout->to_bank) }}
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
                            <span class="text-nowrap {{ $entry->amount < 0 ? 'text-danger' : 'text-success' }}">
                                {{ $entry->amount > 0 ? '+' : '' }}{{ $rub($entry->amount) }}
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

            // ── «Хочу получить»: ручной ввод — к ближайшему кратному шагу в пределах min/max,
            //    кнопки −/+ — на шаг от округлённого значения ──
            document.querySelectorAll('[data-salary-step]').forEach(function (input) {
                const step = Number(input.dataset.salaryStep);

                function snap(value) {
                    let v = Math.round(value / step) * step;
                    if (input.max !== '') v = Math.min(v, Number(input.max));
                    return Math.max(0, v);
                }

                input.addEventListener('change', function () {
                    if (input.value !== '') input.value = snap(Number(input.value));
                });

                input.closest('.input-group').querySelectorAll('[data-salary-step-btn]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        const current = snap(Number(input.value) || 0);
                        input.value = snap(current + Number(btn.dataset.salaryStepBtn) * step);
                    });
                });
            });
        });
    </script>
@endpush
