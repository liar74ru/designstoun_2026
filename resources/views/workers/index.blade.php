@extends('layouts.app')

@section('title', 'Работники')

@section('content')
<div class="container py-3">

    <x-page-header
        title="Список работников"
        mobileTitle="Работники"
        :hide-mobile="true">
        <x-slot name="actions">
            @if(auth()->user()->isAdmin())
                <a href="{{ route('workers.create') }}" class="btn btn-success btn-lg px-4">
                    <i class="bi bi-plus-circle"></i> Добавить работника
                </a>
            @endif
        </x-slot>
    </x-page-header>

    @if(auth()->user()->isAdmin())
        <div class="d-md-none mb-2">
            <a href="{{ route('workers.create') }}" class="btn btn-success w-100">
                <i class="bi bi-plus-circle"></i> Добавить работника
            </a>
        </div>
    @endif

    @include('partials.alerts')

    @if(auth()->user()->isAdmin())
        <div class="btn-group btn-group-sm mb-3 w-100" role="group" aria-label="Фильтр по статусу">
            <a href="{{ route('workers.index', ['filter' => ['status' => 'active']]) }}"
               class="btn {{ $status === 'active' ? 'btn-primary' : 'btn-outline-primary' }}">
                <i class="bi bi-people"></i> Активные
            </a>
            <a href="{{ route('workers.index', ['filter' => ['status' => 'archived']]) }}"
               class="btn {{ $status === 'archived' ? 'btn-primary' : 'btn-outline-primary' }}">
                <i class="bi bi-archive"></i> Архивные
            </a>
            <a href="{{ route('workers.index', ['filter' => ['status' => 'all']]) }}"
               class="btn {{ $status === 'all' ? 'btn-primary' : 'btn-outline-primary' }}">
                Все
            </a>
        </div>
    @endif

    @if($workers->count() > 0)

        {{-- Десктоп --}}
        <div class="d-none d-md-block card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Имя</th>
                        <th>Должность</th>
                        <th>Отдел</th>
                        <th>Телефон</th>
                        <th>Дата добавления</th>
                        <th>Аккаунт</th>
                        <th class="text-center">Действия</th>
                    </tr>
                    </thead>
                    <tbody>
                    @php
                        $positionColors = [
                            'Администратор'    => '#212529',
                            'Мастер'           => '#e6a817',
                            'Помощник мастера' => '#fd7e14',
                            'Работник'         => '#0d6efd',
                            'Разнорабочий'     => '#6c757d',
                        ];
                    @endphp
                    @foreach($workers as $worker)
                        @if(auth()->user()->isAdmin() || !($worker->user?->isAdmin()))
                        <tr>
                            <td class="text-muted small">{{ $worker->id }}</td>
                            <td class="fw-bold">
                                {{ $worker->name }}
                                @if($worker->isArchived())
                                    <span class="badge bg-secondary ms-1" title="Уволен {{ $worker->archived_at->format('d.m.Y') }}">
                                        <i class="bi bi-archive"></i> В архиве
                                    </span>
                                @endif
                            </td>
                            <td>
                                @php
                                    $pos = $worker->position;
                                    $posColor = $positionColors[$pos] ?? '#dee2e6';
                                @endphp
                                @if($pos)
                                    <span class="badge small"
                                          style="background:{{ $posColor }};color:{{ $pos === 'Мастер' ? '#212529' : '#fff' }}">
                                        {{ $pos }}
                                    </span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if($worker->department)
                                    <span class="badge bg-info text-dark">
                                        <i class="bi bi-building"></i> {{ $worker->department->name }}
                                    </span>
                                    @if($worker->department->code)
                                        <br><small class="text-muted">{{ $worker->department->code }}</small>
                                    @endif
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if($worker->phone)
                                    <a href="tel:{{ $worker->phone }}" class="text-decoration-none">
                                        <i class="bi bi-telephone"></i> {{ $worker->phone }}
                                    </a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-muted small">{{ $worker->created_at->format('d.m.Y') }}</td>
                            <td>
                                @if($worker->user)
                                    <a href="{{ route('workers.edit-user', $worker) }}"
                                       class="btn btn-sm btn-success" title="Редактировать учётную запись">
                                        <i class="bi bi-person-check"></i> Связан
                                    </a>
                                @else
                                    @if(auth()->user()->isAdmin())
                                        <a href="{{ route('workers.create-user', $worker) }}"
                                           class="btn btn-sm btn-outline-primary" title="Создать учётную запись">
                                            <i class="bi bi-person-plus"></i> Создать
                                        </a>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                @endif
                            </td>
                            <td>
                                <div class="d-flex gap-1 justify-content-center">
                                    @if($worker->position === 'Работник')
                                        <a href="{{ route('worker.dashboard.by-id', $worker->id) }}"
                                           class="btn btn-sm btn-outline-success" title="Выработка">
                                            <i class="bi bi-bar-chart"></i>
                                        </a>
                                    @endif
                                    @if($worker->position === 'Мастер')
                                        <a href="{{ route('master.dashboard.by-id', $worker->id) }}"
                                           class="btn btn-sm btn-outline-success" title="Выработка мастера">
                                            <i class="bi bi-bar-chart"></i>
                                        </a>
                                    @endif
                                    @if(auth()->user()->isAdmin())
                                        <a href="{{ route('workers.edit', $worker) }}"
                                           class="btn btn-sm btn-outline-primary" title="Редактировать">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @endif
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Мобильный --}}
        <div class="d-md-none">
            @foreach($workers as $worker)
                @if(auth()->user()->isAdmin() || !($worker->user?->isAdmin()))
                @php
                    $pos = $worker->position;
                    $posColor = $positionColors[$pos] ?? '#dee2e6';
                @endphp
                <div class="info-block mb-2"
                     style="border-left:4px solid {{ $posColor }};border-right:4px solid {{ $posColor }}">

                    <div class="info-block-header d-flex justify-content-between align-items-start gap-2">
                        <span class="fw-semibold small">{{ $worker->name }}</span>
                        <div class="d-flex flex-wrap gap-1 justify-content-end">
                            @if($worker->isArchived())
                                <span class="badge small bg-secondary">
                                    <i class="bi bi-archive"></i> В архиве
                                </span>
                            @endif
                            @if($pos)
                                <span class="badge small"
                                      style="background:{{ $posColor }};color:{{ $pos === 'Мастер' ? '#212529' : '#fff' }}">
                                    {{ $pos }}
                                </span>
                            @else
                                <span class="badge small" style="background:#dee2e6;color:#212529">—</span>
                            @endif
                        </div>
                    </div>

                    <div class="info-block-body d-flex gap-2 align-items-stretch">

                        {{-- Левая часть: детали --}}
                        <div class="flex-grow-1 min-w-0 d-flex flex-column justify-content-between">
                            <div>
                                @if($worker->department)
                                    <div class="small text-muted mb-1">
                                        <i class="bi bi-building me-1"></i>{{ $worker->department->name }}
                                        @if($worker->department->code)
                                            <span class="ms-1">{{ $worker->department->code }}</span>
                                        @endif
                                    </div>
                                @endif
                                @if($worker->phone)
                                    <div class="small mb-1">
                                        <a href="tel:{{ $worker->phone }}" class="text-decoration-none text-muted">
                                            <i class="bi bi-telephone me-1"></i>{{ $worker->phone }}
                                        </a>
                                    </div>
                                @endif
                                <div class="small text-muted">
                                    <i class="bi bi-calendar me-1"></i>{{ $worker->created_at->format('d.m.Y') }}
                                </div>
                            </div>
                            <div class="mt-2">
                                @if($worker->user)
                                    <a href="{{ route('workers.edit-user', $worker) }}"
                                       class="btn btn-sm btn-success py-0 px-2" style="font-size:.75rem">
                                        <i class="bi bi-person-check"></i> Аккаунт
                                    </a>
                                @elseif(auth()->user()->isAdmin())
                                    <a href="{{ route('workers.create-user', $worker) }}"
                                       class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:.75rem">
                                        <i class="bi bi-person-plus"></i> Создать аккаунт
                                    </a>
                                @endif
                            </div>
                        </div>

                        {{-- Правая часть: кнопки в столбик --}}
                        <div class="d-flex flex-column gap-1 flex-shrink-0" style="min-width:90px">
                            @if($worker->position === 'Работник')
                                <a href="{{ route('worker.dashboard.by-id', $worker->id) }}"
                                   class="btn btn-sm btn-outline-success w-100">
                                    <i class="bi bi-bar-chart"></i> Выработка
                                </a>
                            @endif
                            @if($worker->position === 'Мастер')
                                <a href="{{ route('master.dashboard.by-id', $worker->id) }}"
                                   class="btn btn-sm btn-outline-success w-100">
                                    <i class="bi bi-bar-chart"></i> Выработка
                                </a>
                            @endif
                            @if(auth()->user()->isAdmin())
                                <a href="{{ route('workers.edit', $worker) }}"
                                   class="btn btn-sm btn-outline-primary w-100">
                                    <i class="bi bi-pencil"></i> Изменить
                                </a>
                            @endif
                        </div>

                    </div>
                </div>
                @endif
            @endforeach
        </div>

        <div class="d-flex justify-content-center mt-3">
            {{ $workers->links() }}
        </div>

    @else
        <div class="text-center py-5">
            <i class="bi bi-people fs-1 text-muted d-block mb-3"></i>
            <h3 class="text-muted mb-3">Работники не найдены</h3>
            <p class="mb-4">Добавьте первого работника в систему</p>
            @if(auth()->user()->isAdmin())
                <a href="{{ route('workers.create') }}" class="btn btn-primary btn-lg">
                    <i class="bi bi-plus-circle"></i> Добавить работника
                </a>
            @endif
        </div>
    @endif

</div>
@endsection
