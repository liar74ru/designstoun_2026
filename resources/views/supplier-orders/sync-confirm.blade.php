@extends('layouts.app')

@section('title', 'Подтверждение синхронизации')

@section('content')
<div class="container py-3 py-md-4">

    <x-page-header
        title="Подтверждение синхронизации"
        mobileTitle="Синхронизация"
        back-url="{{ route('supplier-orders.index') }}"
        back-label="Отмена"
    />

    <div class="row justify-content-center">
        <div class="col-12 col-md-7 col-lg-5">

            @if($issue === 'order_not_created')

                <div class="card shadow-sm border-warning">
                    <div class="card-header bg-warning bg-opacity-10 d-flex align-items-center gap-2">
                        <i class="bi bi-exclamation-triangle text-warning fs-5"></i>
                        <span class="fw-semibold">Заказ поставщику не создан в МойСклад</span>
                    </div>
                    <div class="card-body">
                        <p class="mb-2">
                            Поступление <strong>№{{ $order->number }}</strong> не было передано в МойСклад —
                            Заказ поставщику отсутствует. Без него нельзя создать Приёмку.
                        </p>
                        <p class="text-muted small mb-3">
                            Можно создать только Заказ поставщику, либо сразу оба документа.
                            При конфликте имени суффикс <code>_01</code> будет добавлен автоматически.
                        </p>

                        <div class="d-grid gap-2">
                            <form method="POST" action="{{ route('supplier-orders.force-sync', $order) }}">
                                @csrf
                                <input type="hidden" name="mode" value="recreate">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-arrow-repeat me-1"></i>
                                    Создать Заказ поставщику + Приёмку
                                </button>
                            </form>
                            <form method="POST" action="{{ route('supplier-orders.force-sync', $order) }}">
                                @csrf
                                <input type="hidden" name="mode" value="create_order_only">
                                <button type="submit" class="btn btn-outline-primary w-100">
                                    <i class="bi bi-file-earmark-plus me-1"></i>
                                    Создать только Заказ поставщику
                                </button>
                            </form>
                            <a href="{{ route('supplier-orders.show', $order) }}" class="btn btn-outline-secondary">
                                <i class="bi bi-x-circle me-1"></i> Отмена
                            </a>
                        </div>
                    </div>
                </div>

            @elseif($issue === 'order_missing')

                <div class="card shadow-sm border-warning">
                    <div class="card-header bg-warning bg-opacity-10 d-flex align-items-center gap-2">
                        <i class="bi bi-exclamation-triangle text-warning fs-5"></i>
                        <span class="fw-semibold">Заказ поставщику удалён из МойСклад</span>
                    </div>
                    <div class="card-body">
                        <p class="mb-2">
                            Заказ поставщику <strong>№{{ $order->number }}</strong> был удалён из МойСклад,
                            поэтому Приёмку нельзя создать без него.
                        </p>
                        <p class="text-muted small mb-3">
                            Можно воссоздать Заказ поставщику в МойСклад и сразу создать связанную Приёмку,
                            либо только Заказ поставщику. При конфликте имени суффикс <code>_01</code>
                            будет добавлен автоматически.
                        </p>

                        <div class="d-grid gap-2">
                            <form method="POST" action="{{ route('supplier-orders.force-sync', $order) }}">
                                @csrf
                                <input type="hidden" name="mode" value="recreate">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-arrow-repeat me-1"></i>
                                    Создать Заказ поставщику + Приёмку
                                </button>
                            </form>
                            <form method="POST" action="{{ route('supplier-orders.force-sync', $order) }}">
                                @csrf
                                <input type="hidden" name="mode" value="create_order_only">
                                <button type="submit" class="btn btn-outline-primary w-100">
                                    <i class="bi bi-file-earmark-plus me-1"></i>
                                    Создать только Заказ поставщику
                                </button>
                            </form>
                            <a href="{{ route('supplier-orders.show', $order) }}" class="btn btn-outline-secondary">
                                <i class="bi bi-x-circle me-1"></i> Отмена
                            </a>
                        </div>
                    </div>
                </div>

            @elseif($issue === 'duplicate_supply')

                <div class="card shadow-sm border-warning">
                    <div class="card-header bg-warning bg-opacity-10 d-flex align-items-center gap-2">
                        <i class="bi bi-exclamation-triangle text-warning fs-5"></i>
                        <span class="fw-semibold">Приёмка с таким номером уже существует</span>
                    </div>
                    <div class="card-body">
                        <p class="mb-2">
                            В МойСклад уже есть Приёмка с номером <strong>№{{ $order->number }}</strong>.
                        </p>
                        <p class="text-muted small mb-1">
                            Предлагается создать Приёмку с номером:
                        </p>
                        <div class="alert alert-light border py-2 px-3 mb-3">
                            <span class="fw-semibold font-monospace fs-6">{{ $suggested }}</span>
                        </div>
                        <p class="text-muted small mb-3">
                            Номер поступления в системе также будет обновлён на <strong>{{ $suggested }}</strong>.
                        </p>

                        <div class="d-grid gap-2">
                            <form method="POST" action="{{ route('supplier-orders.force-sync', $order) }}">
                                @csrf
                                <input type="hidden" name="mode" value="suffix_supply">
                                <input type="hidden" name="suggested_name" value="{{ $suggested }}">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-check-circle me-1"></i>
                                    Создать Приёмку «{{ $suggested }}»
                                </button>
                            </form>
                            <a href="{{ route('supplier-orders.index') }}" class="btn btn-outline-secondary">
                                <i class="bi bi-x-circle me-1"></i> Отмена
                            </a>
                        </div>
                    </div>
                </div>

            @else

                <div class="alert alert-danger">
                    Неизвестная проблема синхронизации.
                    <a href="{{ route('supplier-orders.index') }}">Вернуться к списку</a>
                </div>

            @endif

        </div>
    </div>

</div>
@endsection
