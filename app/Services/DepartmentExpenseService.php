<?php

namespace App\Services;

use App\Models\Department;
use Illuminate\Support\Facades\DB;

class DepartmentExpenseService
{
    /**
     * Полная замена списка накладных расходов отдела (replace-all).
     *
     * $rows — сырой ввод формы: [['name' => ?string, 'amount' => ?string], …].
     * Строки, где пусты И имя, И сумма, отбрасываются — это заготовки,
     * добавленные кнопкой и оставленные незаполненными.
     * Пустое имя → «Расход №N», пустая сумма → 0.
     *
     * Следствие replace-all: автосгенерированные имена пересчитываются при
     * каждом сохранении, поэтому вставка строки в середину сдвинет номера
     * у последующих безымянных строк.
     */
    public function sync(Department $department, array $rows): void
    {
        $prepared = [];
        $number   = 1;

        foreach ($rows as $row) {
            $name   = trim((string) ($row['name'] ?? ''));
            $amount = (string) ($row['amount'] ?? '');

            if ($name === '' && $amount === '') {
                continue;
            }

            $prepared[] = [
                'name'   => $name !== '' ? $name : "Расход №{$number}",
                'amount' => (float) $amount,
            ];
            $number++;
        }

        DB::transaction(function () use ($department, $prepared) {
            $department->expenses()->delete();

            if ($prepared) {
                $department->expenses()->createMany($prepared);
            }
        });

        $department->forgetSettingsCache();
    }
}
