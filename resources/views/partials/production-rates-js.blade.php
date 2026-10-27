{{--
    Ставки, коэффициенты и правила себестоимости для JS-превью в формах.

    Зачем: превью коэффициента и зарплаты в формах обязано считаться по тем же
    числам, что и сервер. Раньше значения вписывались в каждый шаблон отдельным
    Setting::get(), часть была захардкожена (подкол 1.5, доля коэффициента 0.17) —
    и копии разъезжались.

    Ставка и правила задаются per-department, а отдел выбирается прямо на
    странице (в формах приёмки и цеха), поэтому отдаём карты «отдел → …»,
    а не значения одного отдела. Карты ограничены отделами, доступными
    пользователю.

    Правила отдаются обеих областей сразу (поле applies_to) — форма фильтрует
    сама. Базовая ставка мастера и накладные отдела нужны авторасчёту
    затрат цеха (processingSum: работник + мастер + накладные).

    Подключать перед скриптами, использующими window.RateFormula.
--}}
@php
    $ratesAccessibleIds = auth()->user()?->accessibleDepartmentIds();

    $ratesDepartments = \App\Models\Department::query()
        ->where('is_active', true)
        ->when($ratesAccessibleIds !== null, fn($q) => $q->whereIn('id', $ratesAccessibleIds))
        ->pluck('id');

    $ratesPieceByDept = [];
    $ratesMasterByDept = [];
    $ratesOverheadByDept = [];
    $ratesModifiersByDept = [];

    foreach ($ratesDepartments as $ratesDeptId) {
        $ratesKey = (string) $ratesDeptId;

        $ratesPieceByDept[$ratesKey] = \App\Support\DepartmentSettings::pieceRate($ratesDeptId);
        $ratesMasterByDept[$ratesKey] = \App\Support\DepartmentSettings::masterBaseRate($ratesDeptId);
        $ratesOverheadByDept[$ratesKey] = \App\Support\DepartmentSettings::overheadPerUnit($ratesDeptId);

        $ratesModifiersByDept[$ratesKey] = \App\Support\ModifierEngine::allFor($ratesDeptId)
            ->map(fn ($rule) => [
                'key'                      => $rule->key,
                'name'                     => $rule->name,
                'color'                    => $rule->color,
                'icon'                     => $rule->icon,
                'trigger'                  => $rule->trigger,
                'sku_pattern'              => $rule->sku_pattern,
                'available_when_batch_sku' => $rule->available_when_batch_sku,
                'applies_to'               => $rule->applies_to,
                'worker_coeff_delta'       => (float) ($rule->worker_coeff_delta ?? 0),
                'master_coeff_delta'       => (float) ($rule->master_coeff_delta ?? 0),
            ])
            ->values();
    }
@endphp
<script>
    window.ProductionRates = {
        global: @json(\App\Support\ProductionRates::all()),
        coeffShare: {{ \App\Support\RateFormula::COEFF_SHARE }},
        pieceRate: {
            default: {{ \App\Support\DepartmentSettings::pieceRate(null) }},
            byDepartment: @json((object) $ratesPieceByDept),
        },
        masterRate: {
            default: {{ \App\Support\DepartmentSettings::masterBaseRate(null) }},
            byDepartment: @json((object) $ratesMasterByDept),
        },
        overhead: {
            byDepartment: @json((object) $ratesOverheadByDept),
        },
        modifiers: {
            byDepartment: @json((object) $ratesModifiersByDept),
        },
    };
</script>
