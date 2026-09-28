@extends('layouts.app')

@section('title', 'Справка: ' . $meta['title'])

@section('content')
<div class="container py-3">

    <x-page-header
        :title="'📖 Справка: ' . $meta['title']"
        :mobile-title="'Справка: ' . $meta['title']"
        :back-url="$meta['back'] && Route::has($meta['back']) ? route($meta['back']) : route('help.index')"
        :back-label="$meta['back'] ? 'К разделу' : 'Вся справка'">
        <x-slot name="actions">
            <a href="{{ route('help.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-journal-text"></i> Вся справка
            </a>
        </x-slot>
    </x-page-header>

    {{-- Текст — docs/{{ $meta['file'] }} без раздела для разработчиков (HelpService). --}}
    <div class="card shadow-sm">
        <div class="card-body help-doc">
            {!! $html !!}
        </div>
    </div>

</div>
@endsection

@include('partials.help-doc-styles')
