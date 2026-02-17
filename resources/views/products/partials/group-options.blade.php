@foreach($groups as $group)
    <option value="{{ $group['id'] }}"
            {{ request('group') == $group['id'] ? 'selected' : '' }}
            style="padding-left: {{ $level * 20 }}px;">
        {{ str_repeat('─ ', $level) }}{{ $group['name'] }}
        @if($group['products_count'] > 0)
            ({{ $group['products_count'] }})
        @endif
    </option>

    @if(!empty($group['children']))
        @include('products.partials.group-options', [
            'groups' => $group['children'],
            'level' => $level + 1
        ])
    @endif
@endforeach
