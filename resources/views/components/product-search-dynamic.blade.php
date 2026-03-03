{{-- resources/views/components/product-search-dynamic.blade.php --}}
@props([
    'products' => [],
    'name' => 'product_id',
    'label' => 'Продукт',
    'placeholder' => 'Начните вводить название или артикул...',
    'required' => true,
    'value' => null,
    'error' => null,
    'maxResults' => 10,
    'index' => null,
])

@php
    $fieldId = $index !== null ? 'product_' . $index : uniqid('product_');
    $inputId = 'productInput-' . $fieldId;
    $hiddenId = 'productIdHidden-' . $fieldId;
    $listId = 'products-list-' . $fieldId;
    $inputName = $index !== null ? $name . '[' . $index . '][product_id]' : $name;
@endphp

<div class="mb-3 product-search-wrapper" data-index="{{ $index }}">
    <label class="form-label">
        {{ $label }}
        @if($required)
            <span class="text-danger">*</span>
        @endif
    </label>

    <input type="text"
           class="form-control product-search-input @if($error) is-invalid @endif"
           list="{{ $listId }}"
           placeholder="{{ $placeholder }}"
           id="{{ $inputId }}"
           data-target="{{ $hiddenId }}"
           data-max-results="{{ $maxResults }}"
           data-products="{{ json_encode($products->mapWithKeys(function($product) {
               return [$product->name . ' (' . $product->sku . ')' => $product->id];
           })) }}"
        {{ $required ? 'required' : '' }}>

    <datalist id="{{ $listId }}"></datalist>

    <input type="hidden"
           name="{{ $inputName }}"
           id="{{ $hiddenId }}"
           value="{{ $value }}"
        {{ $required ? 'required' : '' }}>

    @if($error)
        <div class="invalid-feedback d-block">
            {{ $error }}
        </div>
    @endif
</div>

@once
    <style>
        .product-search-input:invalid {
            border-color: #dc3545;
        }
    </style>

    @vite(['resources/js/product-search.js'])
@endonce
