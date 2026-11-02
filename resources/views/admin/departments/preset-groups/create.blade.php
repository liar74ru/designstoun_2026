@extends('layouts.app')

@section('title', 'Новая группа пресетов — ' . $department->name)

@section('content')
<div class="container py-3" style="max-width:720px">

    <x-page-header
        title="Новая группа пресетов"
        mobileTitle="Новая группа"
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
        'action' => route('admin.departments.preset-groups.store', $department),
        'method' => 'POST',
        'group'  => null,
    ])
</div>
@endsection
