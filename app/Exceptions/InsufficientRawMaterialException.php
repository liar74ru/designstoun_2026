<?php

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * В партии меньше сырья, чем нужно списать. Бросается под блокировкой строки партии
 * (App\Support\BatchStock), поэтому ловит и гонку двух одновременных списаний, которую
 * предварительная проверка в форме пропускает. Не пойманное — возвращает на форму
 * с ошибкой поля.
 */
class InsufficientRawMaterialException extends RuntimeException
{
    public function __construct(
        public readonly string $field = 'raw_quantity_used',
        string $message = 'Недостаточно сырья',
    ) {
        parent::__construct($message);
    }

    public function render(): RedirectResponse
    {
        return back()->withErrors([$this->field => $this->getMessage()])->withInput();
    }
}
