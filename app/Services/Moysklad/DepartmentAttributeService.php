<?php

namespace App\Services\Moysklad;

use App\Models\Department;
use Illuminate\Support\Facades\Log;

/**
 * Связь отдела программы с флажком-реквизитом заказа покупателя в МойСклад.
 *
 * Отдел заявки в МойСклад — булев доп. реквизит заказа с описанием «Отдел». Отдел хранит id
 * этого реквизита (departments.moysklad_attribute_id), поэтому переименование отдела или
 * флажка связь не рвёт, а посторонний флажок с тем же именем отделом не считается.
 */
class DepartmentAttributeService extends MoySkladBaseService
{
    /** Описание реквизита, которым помечены флажки отделов. */
    public const DESCRIPTION = 'Отдел';

    private const ENDPOINT = '/entity/customerorder/metadata/attributes';

    /**
     * Флажки отделов в МойСклад: булевы реквизиты заказа покупателя с описанием «Отдел».
     *
     * @return array<string, string>|null [id реквизита => имя]; null — МойСклад недоступен
     */
    public function departmentAttributes(): ?array
    {
        if (! $this->hasCredentials()) {
            return null;
        }

        $metadata = $this->get(self::ENDPOINT);
        if ($metadata === null) {
            return null;
        }

        $result = [];
        foreach ($metadata['rows'] ?? [] as $attr) {
            if (($attr['type'] ?? null) === 'boolean'
                && isset($attr['id'], $attr['name'])
                && self::isDepartmentDescription($attr['description'] ?? null)) {
                $result[$attr['id']] = $attr['name'];
            }
        }

        return $result;
    }

    /**
     * Связать отдел с флажком МойСклад; null — отвязать.
     *
     * @return array{success: bool, code: string, message: string}
     */
    public function link(Department $department, ?string $attributeId): array
    {
        if ($attributeId === null) {
            $department->update(['moysklad_attribute_id' => null]);

            return ['success' => true, 'code' => '', 'message' => 'Отдел «' . $department->name . '» отвязан от МойСклад.'];
        }

        $attributes = $this->departmentAttributes();
        if ($attributes === null) {
            return ['success' => false, 'code' => 'api_error', 'message' => 'Не удалось получить реквизиты заказа из МойСклад'];
        }

        if (! isset($attributes[$attributeId])) {
            return [
                'success' => false,
                'code'    => 'no_attribute',
                'message' => 'В МойСклад нет флажка заказа с описанием «' . self::DESCRIPTION . '» и таким id',
            ];
        }

        $owner = Department::where('moysklad_attribute_id', $attributeId)
            ->where('id', '!=', $department->id)
            ->first();
        if ($owner) {
            return [
                'success' => false,
                'code'    => 'taken',
                'message' => 'Флажок «' . $attributes[$attributeId] . '» уже связан с отделом «' . $owner->name . '»',
            ];
        }

        $department->update(['moysklad_attribute_id' => $attributeId]);

        return [
            'success' => true,
            'code'    => '',
            'message' => 'Отдел «' . $department->name . '» связан с флажком «' . $attributes[$attributeId] . '».',
        ];
    }

    /**
     * Создать в МойСклад флажок отдела (имя отдела, описание «Отдел») и связать с отделом.
     * Свободный флажок с таким именем уже есть — связываем его, дубль не создаём.
     *
     * @return array{success: bool, code: string, message: string}
     */
    public function createFor(Department $department): array
    {
        $attributes = $this->departmentAttributes();
        if ($attributes === null) {
            return ['success' => false, 'code' => 'api_error', 'message' => 'Не удалось получить реквизиты заказа из МойСклад'];
        }

        $taken = Department::whereNotNull('moysklad_attribute_id')
            ->where('id', '!=', $department->id)
            ->pluck('moysklad_attribute_id')
            ->all();

        foreach ($attributes as $id => $name) {
            if ($name === $department->name && ! in_array($id, $taken, true)) {
                return $this->link($department, $id);
            }
        }

        try {
            $response = $this->post(self::ENDPOINT, [
                'name'        => $department->name,
                'type'        => 'boolean',
                'required'    => false,
                'description' => self::DESCRIPTION,
            ]);
        } catch (\Throwable $e) {
            Log::error('Исключение при создании флажка отдела в МойСклад', [
                'department_id' => $department->id,
                'error'         => $e->getMessage(),
            ]);

            return ['success' => false, 'code' => 'exception', 'message' => 'Ошибка: ' . $e->getMessage()];
        }

        $id = $response->json('id');
        if (! $response->successful() || ! $id) {
            $errors = $response->json()['errors'] ?? [];

            Log::error('Ошибка создания флажка отдела в МойСклад', [
                'department_id' => $department->id,
                'status'        => $response->status(),
                'response'      => $response->json(),
            ]);

            return [
                'success' => false,
                'code'    => 'api_error',
                'message' => 'Ошибка МойСклад: ' . ($errors[0]['error'] ?? 'Неизвестная ошибка'),
            ];
        }

        $department->update(['moysklad_attribute_id' => $id]);

        return [
            'success' => true,
            'code'    => '',
            'message' => 'В МойСклад создан флажок «' . $department->name . '», отдел связан с ним.',
        ];
    }

    /**
     * Привязать по точному совпадению имени отделы, у которых связи ещё нет.
     *
     * Переход со старой схемы «по имени» без ручной работы: зовётся перед синхронизацией
     * заявок. Флажок, уже связанный с другим отделом, не перехватывается. МойСклад
     * недоступен — ничего не делаем.
     */
    public function autoLink(): void
    {
        $unlinked = Department::whereNull('moysklad_attribute_id')->get(['id', 'name']);
        if ($unlinked->isEmpty()) {
            return;
        }

        $attributes = $this->departmentAttributes();
        if (! $attributes) {
            return;
        }

        $taken = Department::whereNotNull('moysklad_attribute_id')->pluck('moysklad_attribute_id')->all();

        foreach ($unlinked as $department) {
            foreach ($attributes as $id => $name) {
                if ($name === $department->name && ! in_array($id, $taken, true)) {
                    $department->update(['moysklad_attribute_id' => $id]);
                    $taken[] = $id;

                    Log::info('Отдел связан с флажком МойСклад по имени', [
                        'department_id' => $department->id,
                        'attribute_id'  => $id,
                    ]);

                    break;
                }
            }
        }
    }

    /** meta-ссылка реквизита заказа покупателя для записи значения флажка. */
    public function attributeMeta(string $attributeId): array
    {
        return [
            'href'      => $this->baseUrl . self::ENDPOINT . '/' . $attributeId,
            'type'      => 'attributemetadata',
            'mediaType' => 'application/json',
        ];
    }

    private static function isDepartmentDescription(?string $description): bool
    {
        return $description !== null
            && mb_strtolower(trim($description)) === mb_strtolower(self::DESCRIPTION);
    }
}
