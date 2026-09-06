@extends('layouts.app')

@section('title', 'Новое правило — ' . $department->name)

@section('content')
<div class="container py-3" style="max-width:720px">

    <x-page-header
        title="Новое правило себестоимости"
        mobileTitle="Новое правило"
        :backUrl="route('admin.departments.show', $department)"
        backLabel="К отделу" />

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
