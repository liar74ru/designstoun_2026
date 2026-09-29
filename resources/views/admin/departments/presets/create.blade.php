@extends('layouts.app')

@section('title', 'Новый пресет — ' . $department->name)

@section('content')
<div class="container py-3" style="max-width:720px">

    <x-page-header
        title="Новый пресет цеха"
        mobileTitle="Новый пресет"
        :backUrl="route('admin.departments.show', $department)"
        backLabel="К отделу">
        <x-slot name="actions">
            @include('partials.help-button', ['page' => 'settings'])
        </x-slot>
        <x-slot name="mobileActions">
            @include('partials.help-button', ['page' => 'settings', 'class' => 'btn-outline-secondary btn-sm'])
        </x-slot>
    </x-page-header>

    @include('partials.alerts')

    @include('admin.departments.presets._form', [
        'action' => route('admin.departments.presets.store', $department),
        'method' => 'POST',
        'preset' => null,
    ])
</div>
@endsection
