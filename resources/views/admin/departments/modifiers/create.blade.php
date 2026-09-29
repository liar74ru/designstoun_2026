@extends('layouts.app')

@section('title', 'Новое правило — ' . $department->name)

@section('content')
<div class="container py-3" style="max-width:720px">

    <x-page-header
        title="Новое правило себестоимости"
        mobileTitle="Новое правило"
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

    @include('admin.departments.modifiers._form', [
        'action'   => route('admin.departments.modifiers.store', $department),
        'method'   => 'POST',
        'modifier' => null,
    ])
</div>
@endsection

@push('scripts')
    @include('partials.production-rates-js')
@endpush
