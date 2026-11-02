@extends('layouts.app')

@section('title', $group->name . ' — группа пресетов ' . $department->name)

@section('content')
<div class="container py-3" style="max-width:720px">

    <x-page-header
        title="{{ $group->name }}"
        mobileTitle="{{ $group->name }}"
        :backUrl="route('admin.departments.show', $department) . '#workshop'"
        backLabel="К отделу">
        <x-slot name="actions">
            @include('partials.help-button', ['page' => 'settings'])
        </x-slot>
        <x-slot name="mobileActions">
            @include('partials.help-button', ['page' => 'settings', 'class' => 'btn-outline-secondary btn-sm'])
        </x-slot>
    </x-page-header>

    @include('partials.alerts')

    @include('admin.departments.preset-groups._form', [
        'action' => route('admin.departments.preset-groups.update', [$department, $group]),
        'method' => 'PATCH',
    ])

    <form method="POST" data-submit-guard class="mt-4 text-end"
          action="{{ route('admin.departments.preset-groups.destroy', [$department, $group]) }}"
          data-confirm="Удалить группу «{{ $group->name }}»? Пресеты останутся без группы.">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn btn-sm btn-outline-danger">
            <i class="bi bi-trash"></i> Удалить группу
        </button>
    </form>
</div>
@endsection
