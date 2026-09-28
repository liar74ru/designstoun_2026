@extends('layouts.app')

@section('title', 'Справка')

@section('content')
<div class="container py-3">

    <x-page-header title="📖 Справка" mobileTitle="Справка" />

    @if($pages === [])
        <div class="text-center py-5 text-muted">Для ваших разделов справки пока нет.</div>
    @else
        <div class="row g-2">
            @foreach($pages as $key => $page)
                <div class="col-12 col-sm-6 col-lg-4">
                    <a href="{{ route('help.show', $key) }}"
                       class="card shadow-sm h-100 text-reset text-decoration-none help-card">
                        <div class="card-body d-flex align-items-center gap-3">
                            <i class="bi {{ $page['icon'] }} fs-3 text-primary"></i>
                            <span class="fw-semibold">{{ $page['title'] }}</span>
                            <i class="bi bi-chevron-right ms-auto text-muted"></i>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
    @endif

</div>
@endsection

@push('styles')
    <style>
        .help-card:hover { border-color: var(--bs-primary); }
    </style>
@endpush
