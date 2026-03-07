@extends('layouts.app')

@section('title', 'Работники')

@section('content')
    <div class="container py-4">
        <!-- Заголовок и кнопка добавления -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2 mb-0">👥 Список работников</h1>

            <a href="{{ route('workers.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Добавить работника
            </a>
        </div>

        <!-- Сообщения об успехе -->
        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill"></i> {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        <!-- Таблица работников -->
        @if($workers->count() > 0)
            <div class="card shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Имя</th>
                            <th>Должность</th>
                            <th>Отдел</th> <!-- НОВЫЙ СТОЛБЕЦ -->
                            <th>Телефон</th>
                            <th>Дата добавления</th>
                            <th>Аккаунт</th>
                            <th class="text-center">Действия</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($workers as $worker)
                            <tr>
                                <td>{{ $worker->id }}</td>
                                <td class="fw-bold">{{ $worker->name }}</td>
                                <td>{{ $worker->position ?? '—' }}</td>
                                <!-- НОВАЯ ЯЧЕЙКА: Отдел -->
                                <td>
                                    @if($worker->department)
                                        <span class="badge bg-info text-dark">
                                            <i class="bi bi-building"></i>
                                            {{ $worker->department->name }}
                                        </span>
                                        @if($worker->department->code)
                                            <br>
                                            <small class="text-muted">{{ $worker->department->code }}</small>
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

                                <td>{{ $worker->created_at->format('d.m.Y') }}</td>
                                <td>
                                    @if($worker->user)
                                        <span class="badge bg-info text-dark">
                                            <i class="bi bi-building"></i>
                                            Связан
                                        </span>
                                    @else
                                        <a href="{{ route('workers.create-user', $worker) }}"
                                           class="btn btn-sm btn-primary">
                                            Создать
                                        </a>
                                    @endif
                                </td>
                                <td>
                                    <div class="d-flex gap-2 justify-content-center">
                                        {{-- Выработка — для пильщиков --}}
                                        @if($worker->position === 'Пильщик')
                                            <a href="{{ route('worker.dashboard.by-id', $worker->id) }}"
                                               class="btn btn-sm btn-outline-success"
                                               title="Выработка">
                                                <i class="bi bi-bar-chart"></i>
                                            </a>
                                        @endif

                                        <!-- Кнопка редактирования -->
                                        <a href="{{ route('workers.edit', $worker) }}"
                                           class="btn btn-sm btn-outline-primary"
                                           title="Редактировать">
                                            <i class="bi bi-pencil"></i>
                                        </a>

                                        <!-- Кнопка удаления с подтверждением -->
                                        <form action="{{ route('workers.destroy', $worker) }}"
                                              method="POST"
                                              class="d-inline"
                                              onsubmit="return confirm('Вы уверены, что хотите удалить работника {{ $worker->name }}?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="btn btn-sm btn-outline-danger"
                                                    title="Удалить">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Пагинация -->
            <div class="d-flex justify-content-center mt-4">
                {{ $workers->links() }}
            </div>
        @else
            <div class="text-center py-5">
                <div class="display-1 text-muted mb-4">👥</div>
                <h3 class="text-muted mb-3">Работники не найдены</h3>
                <p class="mb-4">Добавьте первого работника в систему</p>
                <a href="{{ route('workers.create') }}" class="btn btn-primary btn-lg">
                    <i class="bi bi-plus-circle"></i> Добавить работника
                </a>
            </div>
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        // Автоматическое закрытие алертов через 5 секунд
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                var alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    var bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
        });
    </script>
@endpush
