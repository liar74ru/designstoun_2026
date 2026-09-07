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
    сама. Ставка мастера в формах не показывается, поэтому в JSON не уходит.

    Подключать перед скриптами, использующими window.RateFormula.
--}}
@php
    $ratesAccessibleIds = auth()->user()?->accessibleDepartmentIds();

    $ratesDepartments = \App\Models\Department::query()
        ->where('is_active', true)
        ->when($ratesAccessibleIds !== null, fn($q) => $q->whereIn('id', $ratesAccessibleIds))
        ->pluck('id');

    $ratesPieceByDept = [];
    $ratesModifiersByDept = [];

    foreach ($ratesDepartments as $ratesDeptId) {
        $ratesKey = (string) $ratesDeptId;

        $ratesPieceByDept[$ratesKey] = \App\Support\DepartmentSettings::pieceRate($ratesDeptId);

        $ratesModifiersByDept[$ratesKey] = \App\Support\ModifierEngine::allFor($ratesDeptId)
            ->map(fn ($rule) => [
                'key'                      => $rule->key,
                'name'                     => $rule->name,
                'color'                    => $rule->color,
                'trigger'                  => $rule->trigger,
                'sku_pattern'              => $rule->sku_pattern,
                'available_when_batch_sku' => $rule->available_when_batch_sku,
                'applies_to'               => $rule->applies_to,
                'worker_coeff_delta'       => (float) ($rule->worker_coeff_delta ?? 0),
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
        modifiers: {
            byDepartment: @json((object) $ratesModifiersByDept),
        },
    };
</script>
