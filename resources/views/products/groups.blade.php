@extends('layouts.app')

@section('title', 'Группы товаров')

@section('content')
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2 mb-0">
                <i class="bi bi-folder"></i> Группы товаров
            </h1>

            <div class="btn-group">
                {{-- Кнопка синхронизации групп --}}
                <a href="{{ route('products.groups.sync') }}" class="btn btn-success"
                   onclick="return confirm('Синхронизировать группы с МойСклад?')">
                    <i class="bi bi-arrow-repeat"></i> Синхронизировать группы
                </a>

                <a href="{{ route('products.index') }}" class="btn btn-outline-primary">
                    <i class="bi bi-box"></i> К товарам
                </a>
            </div>
        </div>

        {{-- Добавим отображение сообщений об успехе/ошибке --}}
        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        <div class="row">
            <div class="col-md-8">
                <div class="card shadow-sm">
                    <div class="card-body">
                        @if(empty($groupsTree))
                            <div class="text-center py-5">
                                <i class="bi bi-folder-x" style="font-size: 3rem; color: #ccc;"></i>
                                <p class="text-muted mt-3">Нет групп для отображения</p>
                                <a href="{{ route('products.groups.sync') }}" class="btn btn-success">
                                    <i class="bi bi-arrow-repeat"></i> Синхронизировать группы
                                </a>
                            </div>
                        @else
                            <div class="tree-view">
                                @include('components.group-tree', ['groups' => $groupsTree])
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">Статистика</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled">
                            <li class="mb-2">
                                <i class="bi bi-folder"></i>
                                Всего групп: <strong>{{ $stats['total_groups'] }}</strong>
                            </li>
                            <li class="mb-2">
                                <i class="bi bi-folder2-open"></i>
                                Корневых групп: <strong>{{ $stats['root_groups'] }}</strong>
                            </li>
                            <li class="mb-2">
                                <i class="bi bi-box"></i>
                                Групп с товарами: <strong>{{ $stats['products_in_groups'] }}</strong>
                            </li>
                        </ul>

                        {{-- Добавим информацию о последней синхронизации --}}
                        @if(isset($stats['last_sync']) && $stats['last_sync'])
                            <hr>
                            <small class="text-muted">
                                <i class="bi bi-clock"></i> Последняя синхронизация: {{ $stats['last_sync'] }}
                            </small>
                        @endif
                    </div>
                </div>

                {{-- Добавим подсказку --}}
                <div class="card shadow-sm mt-3">
                    <div class="card-body">
                        <small class="text-muted">
                            <i class="bi bi-info-circle"></i>
                            Группы синхронизируются автоматически при синхронизации товаров.
                            Но вы также можете выполнить синхронизацию только групп.
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        .tree-view ul {
            list-style: none;
            padding-left: 20px;
        }

        .tree-view li {
            margin: 8px 0;
            position: relative;
        }

        .tree-view .folder {
            color: #ffc107;
            margin-right: 8px;
        }

        .tree-view .badge {
            margin-left: 8px;
        }

        .tree-view .toggle-btn {
            cursor: pointer;
            margin-right: 5px;
            color: #6c757d;
            display: inline-block;
            width: 20px;
        }

        .tree-view .group-link {
            text-decoration: none;
            color: #212529;
            padding: 4px 8px;
            border-radius: 4px;
            display: inline-block;
        }

        .tree-view .group-link:hover {
            background-color: #f8f9fa;
            color: #0d6efd;
        }

        .tree-view .children {
            margin-left: 20px;
        }

        .btn-group .btn {
            margin-right: 5px;
        }
    </style>
@endpush
