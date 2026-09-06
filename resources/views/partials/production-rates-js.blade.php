{{--
    Ставки и коэффициенты себестоимости для JS-превью в формах.

    Зачем: превью коэффициента и зарплаты в формах обязано считаться по тем же
    числам, что и сервер. Раньше значения вписывались в каждый шаблон отдельным
    Setting::get(), часть была захардкожена (подкол 1.5, доля коэффициента 0.17) —
    и копии разъезжались.

    PIECE_RATE задаётся per-department, а в форме цеха отдел выбирается прямо на
    странице, поэтому отдаём карту «отдел → ставка», а не одно число.
    Карта ограничена отделами, доступными пользователю.

    Подключать перед скриптами, использующими window.RateFormula.
--}}
@php
    $ratesAccessibleIds = auth()->user()?->accessibleDepartmentIds();

    $ratesDepartments = \App\Models\Department::query()
        ->where('is_active', true)
        ->when($ratesAccessibleIds !== null, fn($q) => $q->whereIn('id', $ratesAccessibleIds))
        ->pluck('id');

    $ratesPieceByDept = [];
    foreach ($ratesDepartments as $ratesDeptId) {
        $ratesPieceByDept[(string) $ratesDeptId] = \App\Support\DepartmentSettings::pieceRate($ratesDeptId);
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
    };
</script>
