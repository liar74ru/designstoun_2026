@extends('layouts.app')

@section('title', 'Группы товаров')

@section('content')
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2 mb-0">
                <i class="bi bi-folder"></i> Группы товаров
            </h1>

            <a href="{{ route('products.index') }}" class="btn btn-outline-primary">
                <i class="bi bi-box"></i> К товарам
            </a>
        </div>

        <div class="row">
            <div class="col-md-8">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="tree-view">
                            @include('components.group-tree', ['groups' => $groupsTree])
                        </div>
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
    </style>
@endpush
