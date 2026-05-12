@extends('layouts.app')

@section('title', 'Статусы заявок')

@section('content')
<div class="container py-3" style="max-width:720px">

    <x-page-header title="Статусы заявок" mobileTitle="Статусы заявок" />

    @include('partials.alerts')

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <p class="text-muted small mb-3">
                Список имён статусов <strong>customerorder</strong> из МойСклад, которые будут подгружаться при синхронизации заявок.
                Имена должны точно совпадать с теми, что заведены в МойСклад (например: «Новая», «В процессе», «Собран»).
            </p>

            <form method="POST" action="{{ route('admin.order-statuses.update') }}"
                  x-data="{ items: {{ json_encode(array_values($statuses) ?: ['']) }} }">
                @csrf

                <template x-for="(s, i) in items" :key="i">
                    <div class="input-group mb-2">
                        <input type="text"
                               class="form-control"
                               style="border-radius:.4rem 0 0 .4rem"
                               :name="'statuses[' + i + ']'"
                               x-model="items[i]"
                               placeholder="Имя статуса в МойСклад"
                               maxlength="100"
                               required>
                        <button type="button"
                                class="btn btn-outline-danger"
                                style="border-radius:0 .4rem .4rem 0"
                                @click="items.splice(i, 1)"
                                x-show="items.length > 1"
                                title="Удалить">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </template>

                @error('statuses')
                    <div class="text-danger small mb-2">{{ $message }}</div>
                @enderror
                @error('statuses.*')
                    <div class="text-danger small mb-2">{{ $message }}</div>
                @enderror

                <div class="d-flex gap-2 mt-3">
                    <button type="button"
                            class="btn btn-outline-secondary"
                            @click="items.push('')">
                        <i class="bi bi-plus-circle"></i> Добавить статус
                    </button>
                    <button type="submit" class="btn btn-primary ms-auto">
                        <i class="bi bi-check-circle"></i> Сохранить
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection
