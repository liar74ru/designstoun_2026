<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', __('Дизайн камень'))</title>
    
    <link rel="icon" type="image/svg+xml" href="{{ asset('dsSvg.svg') }}">
    <link rel="alternate icon" href="{{ asset('dsIso.ico') }}">
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
    <main id="main-content" style="padding-top:64px;min-height:100vh;background-color:#f0f2f5">
        @yield('content')
    </main>
    <!-- Footer -->
    @include('layouts.partials.footer')
    <!-- Scripts -->
    @stack('scripts')
    <script>
        (function () {
            var header = document.querySelector('header');
            var main   = document.getElementById('main-content');
            if (!header || !main) return;
            function syncPadding() {
                main.style.paddingTop = header.offsetHeight + 'px';
            }
            syncPadding();
            window.addEventListener('resize', syncPadding);
            // Повторно после загрузки шрифтов/изображений
            window.addEventListener('load', syncPadding);
        })();
    </script>
</div>
</body>
</html>
