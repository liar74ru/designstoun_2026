@extends('layouts.app')

@section('title', 'Заявки')

@section('content')
<div class="container py-3">

    <x-page-header
        title="📋 Заявки"
        mobileTitle="Заявки"
        :hide-mobile="true">
        <x-slot name="actions">
            @include('partials.help-button', ['page' => 'orders', 'class' => 'btn-outline-secondary btn-lg'])
            <form method="POST" action="{{ route('orders.sync') }}" class="d-inline sync-form">
                @csrf
                <button type="submit" class="btn btn-primary btn-lg px-4"
                        onclick="return confirm('Синхронизировать заявки и остатки?')">
                    <i class="bi bi-cloud-download"></i> Синхронизировать
                </button>
            </form>
        </x-slot>
    </x-page-header>

    {{-- Мобильные кнопки --}}
    <div class="d-md-none mb-2 d-flex gap-2">
        <form method="POST" action="{{ route('orders.sync') }}" class="sync-form flex-grow-1">
            @csrf
            <button type="submit" class="btn btn-primary w-100"
                    onclick="return confirm('Синхронизировать заявки и остатки?')">
                <i class="bi bi-cloud-download"></i> Синхронизировать
            </button>
        </form>
        @include('partials.help-button', ['page' => 'orders'])
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
        'showHiddenOption'   => true,
    ])

    @include('partials.department-switcher', [
        'departments' => $switchDepartments,
        'routeName'   => 'orders.index',
    ])

    @if($orders->count() > 0)

        {{-- Десктоп --}}
        <div class="d-none d-md-block card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:1%" title="Очередь: срочные, затем по сроку отгрузки и дате">Очередь</th>
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
                        <tr class="order-row {{ $order->is_urgent ? 'order-urgent' : '' }}" style="cursor:pointer"
                            data-href="{{ route('orders.show', $order->moysklad_id) }}">
                            <td class="align-top">
                                @include('orders.partials.priority-controls', ['order' => $order])
                            </td>
                            <td class="fw-semibold align-top">
                                <a href="{{ route('orders.show', $order->moysklad_id) }}" class="text-reset">
                                    {{ $order->name }}
                                </a>
                                @include('orders.partials.changed-badge', ['order' => $order, 'class' => 'd-table mt-1'])
                            </td>
                            <td class="text-muted small align-top" style="white-space:nowrap">
                                {{ $order->moment ? $order->moment->format('d.m.Y') : '—' }}
                                <button type="button" class="btn btn-link btn-sm p-0 d-block text-decoration-none small
                                               {{ $order->delivery_planned_at?->copy()->startOfDay()->lt(now()->startOfDay()) ? 'text-danger fw-semibold' : 'text-primary' }}"
                                        data-bs-toggle="modal" data-bs-target="#order-delivery-date-modal"
                                        data-action="{{ route('orders.delivery-date.update', $order->moysklad_id) }}"
                                        data-name="{{ $order->name }}"
                                        data-date="{{ $order->delivery_planned_at?->format('Y-m-d') }}"
                                        title="Дата готовности — нажмите, чтобы изменить">
                                    <i class="bi bi-flag-fill"></i>
                                    {{ $order->delivery_planned_at ? $order->delivery_planned_at->format('d.m.Y') : 'срок' }}
                                </button>
                            </td>
                            <td class="align-top">{{ $order->counterparty?->name ?? $order->agent_name ?? '—' }}</td>
                            <td class="align-top p-0">
                                @include('partials.order-items-table', [
                                    'rows'        => $rowsByOrder[$order->id] ?? collect(),
                                    'order'       => $order,
                                    'hiddenCount' => $hiddenCountByOrder[$order->id] ?? 0,
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
                    'hiddenCount' => $hiddenCountByOrder[$order->id] ?? 0,
                    'orderStates' => $orderStates,
                ])
            @endforeach
        </div>

        <div class="d-flex justify-content-center mt-3">
            {{ $orders->links() }}
        </div>

        @include('orders.partials.departments-modal', ['departments' => $assignDepartments])
        @include('orders.partials.delivery-date-modal')

    @else
        <div class="text-center py-5">
            <i class="bi bi-inbox display-1 text-muted"></i>
            <h3 class="text-muted mt-3">Заявок нет</h3>
            <p class="mb-4">Нажмите «Синхронизировать», чтобы подтянуть заявки из МойСклад.</p>
        </div>
    @endif

</div>
@endsection

@include('orders.partials.ready-toggle-assets')
@include('orders.partials.hide-toggle-assets')
@include('partials.order-card-assets')

@push('styles')
    <style>
        .sync-form button[disabled] {
            opacity: .7;
            cursor: wait;
        }
    </style>
@endpush

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Клик по строке таблицы и по мобильной карточке открывает карточку заявки;
            // ссылки внутри (товары, номер заявки) работают как обычно.
            document.querySelectorAll('.order-row').forEach(function (row) {
                row.addEventListener('click', function (e) {
                    if (e.target.closest('a, button, input, label, summary, .order-priority')) return;
                    window.location = row.dataset.href;
                });
            });

            // Мобильная карточка свёрнута по умолчанию — шеврон разворачивает её
            document.querySelectorAll('[data-ocard-toggle]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    btn.closest('.ocard').classList.toggle('is-compact');
                });
            });

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
