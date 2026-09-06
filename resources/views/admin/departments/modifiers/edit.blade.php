@extends('layouts.app')

@section('title', $modifier->name . ' — правило отдела ' . $department->name)

@section('content')
<div class="container py-3" style="max-width:720px">

    <x-page-header
        title="{{ $modifier->name }}"
        mobileTitle="{{ $modifier->name }}"
        :backUrl="route('admin.departments.show', $department)"
        backLabel="К отделу" />

    @include('partials.alerts')

    <div class="alert alert-light border py-2 small">
        <i class="bi bi-info-circle me-1"></i>
        Правка действует на новые документы. Уже посчитанные ставки не меняются — применённые
        правила хранятся в позициях снимком.
    </div>

    @include('admin.departments.modifiers._form', [
        'action' => route('admin.departments.modifiers.update', [$department, $modifier]),
        'method' => 'PATCH',
    ])
</div>
@endsection

@push('scripts')
    @include('partials.production-rates-js')
@endpush
