@extends('layouts.app')

@section('title', 'Новый пресет — ' . $department->name)

@section('content')
<div class="container py-3" style="max-width:720px">

    <x-page-header
        title="Новый пресет цеха"
        mobileTitle="Новый пресет"
        :backUrl="route('admin.departments.show', $department)"
        backLabel="К отделу" />

    @include('partials.alerts')

    @include('admin.departments.presets._form', [
        'action' => route('admin.departments.presets.store', $department),
        'method' => 'POST',
        'preset' => null,
    ])
</div>
@endsection
