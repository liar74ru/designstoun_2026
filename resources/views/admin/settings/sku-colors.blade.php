@extends('layouts.app')

@section('title', 'Цвета по SKU')

@section('content')
<div class="container py-3" style="max-width:720px">

    <x-page-header
        title="Цвета по SKU"
        mobileTitle="Цвета по SKU"
        :backUrl="route('admin.settings.index')"
        backLabel="К настройкам">
        <x-slot name="actions">
            @include('partials.help-button', ['page' => 'settings'])
        </x-slot>
        <x-slot name="mobileActions">
            @include('partials.help-button', ['page' => 'settings', 'class' => 'btn-outline-secondary btn-sm'])
        </x-slot>
    </x-page-header>

    @include('partials.alerts')

    @php
        // Префиксы в поле — строкой «01-07, 04-07»; после ошибки валидации old() отдаёт уже массив
        $skuColorRows = collect(old('rows', $skuColors->map(fn($c) => [
            'name'     => $c->name,
            'color'    => $c->color,
            'prefixes' => $c->prefixes,
        ])->all()))->map(fn($r) => [
            'name'     => $r['name'] ?? '',
            'color'    => $r['color'] ?? '#BFBFBF',
            'prefixes' => is_array($r['prefixes'] ?? null) ? implode(', ', $r['prefixes']) : ($r['prefixes'] ?? ''),
        ])->values()->all();
    @endphp
    <div class="card shadow-sm mb-3" x-data="{ rows: {{ json_encode(array_values($skuColorRows)) }} }">
        <div class="card-body p-2 p-md-3">
            <form method="POST" action="{{ route('admin.sku-colors.update') }}" data-submit-guard>
                @csrf

                <p class="text-muted small mb-3">
                    Цвет полосы и фона карточек по типу камня. У цвета — список префиксов через
                    запятую: первые два сегмента SKU («01-07, 04-07» — сырьё и плитка) или один
                    сегмент («01» — цвет всей группы, если у камня нет своего). SKU без
                    подходящего префикса остаётся без цвета.
                </p>

                <template x-for="(row, i) in rows" :key="i">
                    <div class="input-group input-group-sm mb-2">
                        <span class="input-group-text p-0" style="width:10px"
                              :style="'border-left:4px solid ' + row.color + ';background:' + row.color + '18'"></span>
                        <input type="text" class="form-control" style="max-width:160px"
                               :name="'rows[' + i + '][name]'"
                               x-model="row.name"
                               maxlength="100"
                               placeholder="Название камня">
                        <input type="text" class="form-control"
                               :name="'rows[' + i + '][prefixes]'"
                               x-model="row.prefixes"
                               placeholder="01-07, 04-07"
                               required>
                        <input type="color" class="form-control form-control-color"
                               :name="'rows[' + i + '][color]'"
                               x-model="row.color"
                               title="Цвет">
                        <button type="button" class="btn btn-outline-danger" @click="rows.splice(i, 1)"
                                title="Удалить цвет">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </template>

                <div x-show="rows.length === 0" class="text-muted small mb-2">
                    Цвета не заданы — все карточки белые.
                </div>

                @if($errors->has('rows') || $errors->has('rows.*'))
                    <div class="text-danger small mb-2">{{ $errors->first('rows') ?: $errors->first('rows.*') }}</div>
                @endif

                <div class="mt-3 d-flex justify-content-between">
                    <button type="button" class="btn btn-sm btn-outline-primary"
                            @click="rows.push({ name: '', color: '#BFBFBF', prefixes: '' })">
                        <i class="bi bi-plus-circle"></i> Добавить цвет
                    </button>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-check-lg"></i> Сохранить
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection
