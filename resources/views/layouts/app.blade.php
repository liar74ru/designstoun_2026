<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', __('Task Manager'))</title>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css?family=Nunito:400,600,700,800&display=swap" rel="stylesheet">

    <!-- Bootstrap 5.3.3 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/css/custom.css', 'resources/js/app.js'])
    @vite(['resources/js/tree-view.js'])
    @vite(['resources/js/dropdown-tree.js'])
    @stack('styles')
    {{--        @include('layouts.partials.header')--}}
</head>
<body>
<div id="app">
    <!-- Header -->
    @include('layouts.partials.header')

    <!-- Flash сообщения ПОД header -->
    {{--    <div class="flash-messages-container mt-5 pt-4">--}}
    {{--        @include('flash::message')--}}
    {{--    </div>--}}

    <!-- Основной контент -->
    <main style="padding-top: {{ auth()->check() && auth()->user()->isMaster() ? '88px' : '60px' }}">
        @yield('content')
    </main>
    <!-- Footer -->
    @include('layouts.partials.footer')
    <!-- Scripts -->
    @stack('scripts')
</div>
</body>
</html>
