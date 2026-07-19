@extends('layouts.app')

@section('title', 'Пресет «' . $preset->name . '» — ' . $department->name)

@section('content')
<div class="container py-3" style="max-width:720px">

    <x-page-header
        title="Пресет «{{ $preset->name }}»"
        mobileTitle="Пресет"
        :backUrl="route('admin.departments.show', $department)"
        backLabel="К отделу" />

    @include('partials.alerts')

    @include('admin.departments.presets._form', [
        'action' => route('admin.departments.presets.update', [$department, $preset]),
        'method' => 'PATCH',
    ])
</div>
@endsection
