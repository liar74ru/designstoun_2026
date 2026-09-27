@extends('layouts.app')

@section('title', 'Заявки')

@section('content')
<div class="container py-3">

    <x-page-header
        title="📋 Заявки"
        mobileTitle="Заявки"
        :hide-mobile="true">
        <x-slot name="actions">
            <form method="POST" action="{{ route('orders.sync') }}" class="d-inline sync-form">
                @csrf
                <button type="submit" class="btn btn-primary btn-lg px-4"
                        onclick="return confirm('Синхронизировать заявки и остатки?')">
                    <i class="bi bi-cloud-download"></i> Синхронизировать
                </button>
            </form>
        </x-slot>
    </x-page-header>

    {{-- Мобильная кнопка --}}
    <div class="d-md-none mb-2">
        <form method="POST" action="{{ route('orders.sync') }}" class="sync-form">
            @csrf
            <button type="submit" class="btn btn-primary w-100"
                    onclick="return confirm('Синхронизировать заявки и остатки?')">
                <i class="bi bi-cloud-download"></i> Синхронизировать
            </button>
        </form>
    </div>

    @include('partials.alerts')

    @include('partials.filters', [
        'filterCutters'      => null,
        'filterRawProducts'  => null,
        'filterProducts'     => null,
        'showStatus'         => 'multi',
        'statusOptions'      => $statusOptions,
        'statusDefaults'     => $statusDefaults,
        'filterDepartments'  => $filterDepartments,
        'departmentDefaults' => $departmentDefaults,
        'departmentNoneValue' => $noDepartmentOption,
    ])

    @include('partials.department-switcher', [
        'departments' => $switchDepartments,
        'routeName'   => 'orders.index',
    ])

    @if($orders->count() > 0)
    <div class="orders-list">

        {{-- Десктоп --}}
        <div class="d-none d-md-block card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Номер</th>
                            <th>Дата</th>
                            <th>Контрагент</th>
                            <th>Товары</th>
                            <th>Отделы</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($orders as $order)
                        <tr class="order-row" style="cursor:pointer"
                            data-href="{{ route('orders.show', $order->moysklad_id) }}">
                            <td class="fw-semibold align-top">
                                <a href="{{ route('orders.show', $order->moysklad_id) }}" class="text-reset">
                                    {{ $order->name }}
                                </a>
                            </td>
                            <td class="text-muted small align-top">
                                {{ $order->moment ? $order->moment->format('d.m.Y') : '—' }}
                            </td>
                            <td class="align-top">{{ $order->counterparty?->name ?? $order->agent_name ?? '—' }}</td>
                            <td class="align-top p-0">
                                @include('partials.order-items-table', [
                                    'rows'  => $rowsByOrder[$order->id] ?? collect(),
                                    'order' => $order,
                                ])
                            </td>
                            <td class="align-top">
                                @include('orders.partials.departments-button', ['order' => $order])
                            </td>
                            <td class="align-top">
                                @include('orders.partials.state-picker', [
                                    'order'  => $order,
                                    'states' => $orderStates,
                                    'size'   => 'sm',
                                ])
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Мобильный --}}
        <div class="d-md-none">
            @foreach($orders as $order)
                @include('partials.order-card', [
                    'order'       => $order,
                    'rows'        => $rowsByOrder[$order->id] ?? collect(),
                    'orderStates' => $orderStates,
                ])
            @endforeach
        </div>

        <div class="d-flex justify-content-center mt-3">
            {{ $orders->links() }}
        </div>

        @include('orders.partials.departments-modal', ['departments' => $assignDepartments])

    </div>
    @else
        <div class="text-center py-5">
            <i class="bi bi-inbox display-1 text-muted"></i>
            <h3 class="text-muted mt-3">Заявок нет</h3>
            <p class="mb-4">Нажмите «Синхронизировать», чтобы подтянуть заявки из МойСклад.</p>
        </div>
    @endif

</div>
@endsection

@push('styles')
    <style>
        .sync-form button[disabled] {
            opacity: .7;
            cursor: wait;
        }

        /* Кнопка ручной отметки «готово» */
        .ready-toggle {
            width: 18px;
            height: 18px;
            margin-top: 2px;
            padding: 0;
            border: 1px solid #ced4da;
            border-radius: 50%;
            background: #fff;
            color: #ced4da;
            font-size: .7rem;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .ready-toggle:hover { color: #16a34a; border-color: #16a34a; }
        .ready-toggle[disabled] { opacity: .5; cursor: wait; }
        .order-pos.is-ready .ready-toggle {
            background: #16a34a;
            border-color: #16a34a;
            color: #fff;
        }

        /* Готовая позиция: приглушённая строка, вместо «Всего» — плашка «готово» */
        .ready-badge { display: none; }
        .order-pos.is-ready > td { opacity: .55; }
        .order-pos.is-ready .total-value { display: none; }
        .order-pos.is-ready .ready-badge { display: inline-block; }
    </style>
@endpush

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Клик по строке таблицы и по мобильной карточке открывает карточку заявки;
            // ссылки внутри (товары, номер заявки) работают как обычно.
            document.querySelectorAll('.order-row').forEach(function (row) {
                row.addEventListener('click', function (e) {
                    if (e.target.closest('a, button, input, label')) return;
                    window.location = row.dataset.href;
                });
            });

            const list = document.querySelector('.orders-list');
            if (list) {
                // Ручная отметка позиции: позиция нарисована дважды (десктоп и мобильная
                // карточка), поэтому перекрашиваем все строки с тем же data-position.
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
                list.addEventListener('click', function (e) {
                    const btn = e.target.closest('.ready-toggle');
                    if (!btn) return;

                    const row = btn.closest('.order-pos');
                    const ready = !row.classList.contains('is-ready');
                    btn.disabled = true;

                    fetch(btn.dataset.url, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrf,
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ ready: ready }),
                    })
                        .then(function (r) {
                            if (!r.ok) throw new Error(r.status);
                            return r.json();
                        })
                        .then(function (data) {
                            list.querySelectorAll('.order-pos[data-position="' + row.dataset.position + '"]')
                                .forEach(function (tr) { tr.classList.toggle('is-ready', data.ready); });
                        })
                        .catch(function () {
                            alert('Не удалось сохранить отметку. Обновите страницу и попробуйте снова.');
                        })
                        .finally(function () { btn.disabled = false; });
                });
            }

            document.querySelectorAll('form.sync-form').forEach(function (form) {
                form.addEventListener('submit', function () {
                    const btn = form.querySelector('button[type=submit]');
                    if (!btn) return;
                    btn.disabled = true;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Выполняется…';
                });
            });
        });
    </script>
@endpush
