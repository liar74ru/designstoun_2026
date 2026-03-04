@extends('layouts.app')

@section('title', 'Приемки камня')

@section('content')
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2 mb-0">📦 Приемки камня</h1>

            <div class="d-flex gap-2">
                <button type="button" class="btn btn-success" id="sendToMoySkladBtn" disabled>
                    <i class="bi bi-cloud-upload"></i> Отправить в МойСклад (<span id="selectedCount">0</span>)
                </button>

                <a href="{{ route('stone-receptions.create') }}" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> Новая приемка
                </a>
            </div>
        </div>

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill"></i> {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill"></i> {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if($receptions->count() > 0)
            <div class="card shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                        <tr>
                            <th width="50">
                                <input type="checkbox" class="form-check-input" id="selectAll">
                            </th>
                            <th>#</th>
                            <th>Дата</th>
                            <th>Продукция</th>
                            <th>Всего</th>
                            <th>Сырье</th>
                            <th>Расход</th>
                            <th>Приемщик</th>
                            <th>Пильщик</th>
                            <th>Склад</th>
                            <th>Статус</th>
                            <th>Действия</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($receptions as $reception)
                            <tr class="{{ $reception->status == 'processed' ? 'table-success' : ($reception->status == 'error' ? 'table-danger' : '') }}">
                                <td>
                                    @if($reception->status == 'active')
                                        <input type="checkbox"
                                               class="form-check-input reception-checkbox"
                                               value="{{ $reception->id }}">
                                    @endif
                                </td>
                                <td>{{ $reception->id }}</td>
                                <td>{{ $reception->created_at->format('d.m.Y H:i') }}</td>
                                <td>
                                    @foreach($reception->items as $item)
                                        <div class="mb-1">
                                            <a href="{{ route('products.show', $item->product->moysklad_id) }}">
                                                <strong>{{ $item->product->name }}</strong>
                                            </a>
                                            <br>
                                            <small class="text-muted">{{ $item->product->sku }}</small>
                                            <span class="badge bg-info ms-2">{{ number_format($item->quantity, 3) }} м²</span>
                                        </div>
                                        @if(!$loop->last)
                                            <hr class="my-1">
                                        @endif
                                    @endforeach
                                </td>
                                <td>
                                    <span class="badge bg-primary">{{ number_format($reception->total_quantity, 3) }} м²</span>
                                </td>
                                <td>
                                    @if($reception->rawMaterialBatch)
                                        <a href="{{ route('raw-batches.show', $reception->rawMaterialBatch) }}">
                                            {{ $reception->rawMaterialBatch->product->name }}
                                        </a>
                                        <br>
                                        <small class="text-muted">Партия #{{ $reception->rawMaterialBatch->id }}</small>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge bg-warning">{{ number_format($reception->raw_quantity_used, 3) }} м³</span>
                                </td>
                                <td>{{ $reception->receiver->name }}</td>
                                <td>{{ $reception->cutter->name ?? '—' }}</td>
                                <td>{{ $reception->store->name ?? '—' }}</td>
                                <td>
                                    @if($reception->status == 'active')
                                        <span class="badge bg-success">Активна</span>
                                    @elseif($reception->status == 'processed')
                                        <span class="badge bg-secondary">Обработана</span>
                                        @if($reception->moysklad_processing_id)
                                            <br>
                                            <small class="text-muted">ID: {{ substr($reception->moysklad_processing_id, 0, 8) }}...</small>
                                        @endif
                                    @elseif($reception->status == 'error')
                                        <span class="badge bg-danger">Ошибка</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="d-flex gap-2">
                                        @if($reception->status == 'active')
                                            <a href="{{ route('stone-receptions.edit', $reception) }}"
                                               class="btn btn-sm btn-outline-primary"
                                               title="Редактировать">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        @endif
                                        <a href="{{ route('stone-receptions.show', $reception) }}"
                                           class="btn btn-sm btn-outline-info"
                                           title="Просмотр">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                            <!-- В колонке Действия, после других кнопок -->
                                            @if($reception->status != 'active')
                                                <form action="{{ route('stone-receptions.reset-status', $reception) }}"
                                                      method="POST"
                                                      class="d-inline"
                                                      onsubmit="return confirm('Сбросить статус приемки на Активна?')">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="btn btn-sm btn-outline-warning" title="Сбросить статус">
                                                        <i class="bi bi-arrow-counterclockwise"></i>
                                                    </button>
                                                </form>
                                            @endif
                                        @if($reception->status == 'active')
                                            <form action="{{ route('stone-receptions.destroy', $reception) }}"
                                                  method="POST"
                                                  class="d-inline"
                                                  onsubmit="return confirm('Удалить приемку? Это также удалит все позиции продукции.')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Удалить">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-4">
                <div>
                <span class="text-muted">
                    Показано {{ $receptions->firstItem() }} - {{ $receptions->lastItem() }} из {{ $receptions->total() }} приемок
                </span>
                </div>
                <div>
                    {{ $receptions->links() }}
                </div>
            </div>
        @else
            <div class="text-center py-5">
                <i class="bi bi-inbox display-1 text-muted"></i>
                <h3 class="text-muted mt-3">Нет приемок</h3>
                <p class="mb-4">Создайте первую приемку камня</p>
                <a href="{{ route('stone-receptions.create') }}" class="btn btn-primary btn-lg">
                    <i class="bi bi-plus-circle"></i> Новая приемка
                </a>
            </div>
        @endif
    </div>

    <!-- Модальное окно для подтверждения отправки -->
    <div class="modal fade" id="confirmModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Подтверждение отправки</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Вы действительно хотите отправить выбранные приемки в МойСклад?</p>
                    <div class="alert alert-info">
                        <strong>Выбрано приемок: <span id="modalSelectedCount">0</span></strong>
                    </div>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        После отправки приемки получат статус "Обработаны" и не будут доступны для повторной отправки.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-success" id="confirmSendBtn">
                        <i class="bi bi-cloud-upload"></i> Отправить
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно для результата -->
    <div class="modal fade" id="resultModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="resultModalTitle">Результат</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="resultModalBody">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно загрузки -->
    <div class="modal fade" id="loadingModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center p-4">
                    <div class="spinner-border text-primary mb-3" style="width: 3rem; height: 3rem;" role="status">
                        <span class="visually-hidden">Загрузка...</span>
                    </div>
                    <p class="mb-0">Отправка данных в МойСклад...</p>
                    <small class="text-muted">Это может занять несколько секунд</small>
                </div>
            </div>
        </div>
    </div>

@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Скрипт загружен');

            // Элементы
            const selectAllCheckbox = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.reception-checkbox');
            const sendBtn = document.getElementById('sendToMoySkladBtn');
            const selectedCountSpan = document.getElementById('selectedCount');

            console.log('Найдено чекбоксов:', checkboxes.length);

            // Функция обновления счетчика выбранных элементов
            function updateSelectedCount() {
                const checked = document.querySelectorAll('.reception-checkbox:checked');
                const count = checked.length;
                console.log('Выбрано:', count);

                if (selectedCountSpan) {
                    selectedCountSpan.textContent = count;
                }

                if (sendBtn) {
                    sendBtn.disabled = count === 0;
                }

                // Обновляем состояние чекбокса "Выделить все"
                if (selectAllCheckbox) {
                    if (checkboxes.length > 0) {
                        selectAllCheckbox.checked = count === checkboxes.length;
                        selectAllCheckbox.indeterminate = count > 0 && count < checkboxes.length;
                    }
                }
            }

            // Добавляем обработчики на каждый чекбокс
            checkboxes.forEach((checkbox, index) => {
                console.log(`Добавлен обработчик для чекбокса ${index + 1}`);
                checkbox.addEventListener('change', updateSelectedCount);
            });

            // Выделить все
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    console.log('Select all changed:', this.checked);
                    checkboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                    updateSelectedCount();
                });
            }

            // Открыть диалог подтверждения
            if (sendBtn) {
                sendBtn.addEventListener('click', function() {
                    const checked = document.querySelectorAll('.reception-checkbox:checked');
                    const count = checked.length;

                    if (count === 0) {
                        alert('Выберите хотя бы одну приемку');
                        return;
                    }

                    const confirmResult = confirm(`Отправить ${count} приемок в МойСклад?`);

                    if (confirmResult) {
                        // Показываем индикатор загрузки
                        sendBtn.disabled = true;
                        sendBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Отправка...';

                        const checkedIds = Array.from(checked).map(cb => cb.value);
                        console.log('Отправка IDs:', checkedIds);

                        fetch('{{ route("stone-receptions.batch.send-to-processing") }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': '{{ csrf_token() }}'
                            },
                            body: JSON.stringify({ reception_ids: checkedIds })
                        })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    alert(data.message);
                                    // Перезагружаем страницу
                                    window.location.reload();
                                } else {
                                    alert('Ошибка: ' + data.message);
                                    // Возвращаем кнопку в исходное состояние
                                    sendBtn.disabled = false;
                                    sendBtn.innerHTML = '<i class="bi bi-cloud-upload"></i> Отправить в МойСклад (<span id="selectedCount">' + count + '</span>)';
                                }
                            })
                            .catch(error => {
                                console.error('Ошибка:', error);
                                alert('Произошла ошибка при отправке запроса');

                                // Возвращаем кнопку в исходное состояние
                                sendBtn.disabled = false;
                                sendBtn.innerHTML = '<i class="bi bi-cloud-upload"></i> Отправить в МойСклад (<span id="selectedCount">' + count + '</span>)';
                            });
                    }
                });
            }

            // Инициализация
            updateSelectedCount();
        });
    </script>
@endpush
