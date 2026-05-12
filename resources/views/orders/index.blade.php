@extends('layouts.app')

@section('title', 'Заявки')

@section('content')
<div class="container py-3">

    <x-page-header
        title="📋 Заявки"
        mobileTitle="Заявки"
        :hide-mobile="true">
        <x-slot name="actions">
            <form method="POST" action="{{ route('orders.sync') }}" class="d-inline"
                  onsubmit="return confirm('Синхронизировать заявки и остатки?')">
                @csrf
                <button type="submit" class="btn btn-primary btn-lg px-4">
                    <i class="bi bi-cloud-download"></i> Синхронизировать
                </button>
            </form>
        </x-slot>
    </x-page-header>

    {{-- Мобильная кнопка --}}
    <div class="d-md-none mb-2">
        <form method="POST" action="{{ route('orders.sync') }}"
              onsubmit="return confirm('Синхронизировать заявки и остатки?')">
            @csrf
            <button type="submit" class="btn btn-primary w-100">
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
    ])

    @if($orders->count() > 0)

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
                        <tr>
                            <td class="fw-semibold align-top">{{ $order->name }}</td>
                            <td class="text-muted small align-top">
                                {{ $order->moment ? $order->moment->format('d.m.Y') : '—' }}
                            </td>
                            <td class="align-top">{{ $order->counterparty?->name ?? $order->agent_name ?? '—' }}</td>
                            <td class="align-top p-0">
                                @include('partials.order-items-table', ['order' => $order, 'productionStoreId' => $productionStoreId])
                            </td>
                            <td class="align-top">
                                @forelse($order->departments as $dept)
                                    <span class="badge bg-light text-dark border me-1">{{ $dept->name }}</span>
                                @empty
                                    <span class="text-muted small">—</span>
                                @endforelse
                            </td>
                            <td class="align-top">
                                <span class="badge text-white"
                                      style="background-color: {{ \App\Models\Order::stateColor($order->state_name) }}">
                                    {{ $order->state_name ?? '—' }}
                                </span>
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
                @include('partials.order-card', ['order' => $order, 'productionStoreId' => $productionStoreId])
            @endforeach
        </div>

        <div class="d-flex justify-content-center mt-3">
            {{ $orders->links() }}
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
