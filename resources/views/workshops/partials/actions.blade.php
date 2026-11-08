{{-- Кнопки действий операции цеха. Параметры: $workshop, $btnSize (px, по умолчанию 22). --}}
@php
    $canModify = auth()->user()?->can('modify', $workshop);
    $isActive  = $workshop->status === 'active';
    $size      = $btnSize ?? 22;
    $btnStyle  = "width:{$size}px;height:{$size}px;padding:0;font-size:" . ($size >= 28 ? '.8rem' : '.65rem');
@endphp
<div class="d-flex gap-1 align-items-center flex-shrink-0">
    @if($canModify && $isActive)
        <a href="{{ route('workshops.edit', $workshop) }}"
           class="btn btn-success d-inline-flex align-items-center justify-content-center"
           style="{{ $btnStyle }}" title="Изменить">
            <i class="bi bi-pencil"></i>
        </a>
        <form action="{{ route('workshops.mark-completed', $workshop) }}" method="POST" class="d-inline-flex"
              data-confirm="Закрыть операцию?" data-submit-guard>
            @csrf @method('PATCH')
            <button type="submit" class="btn btn-warning d-inline-flex align-items-center justify-content-center"
                    style="{{ $btnStyle }}" title="Закрыть">
                <i class="bi bi-check2-circle"></i>
            </button>
        </form>
    @endif
    <form action="{{ route('workshops.copy', $workshop) }}" method="POST" class="d-inline-flex" data-submit-guard>
        @csrf
        <button type="submit" class="btn btn-outline-info d-inline-flex align-items-center justify-content-center"
                style="{{ $btnStyle }}" title="Копия">
            <i class="bi bi-copy"></i>
        </button>
    </form>
    <a href="{{ route('workshops.show', $workshop) }}"
       class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center"
       style="{{ $btnStyle }}" title="Просмотр">
        <i class="bi bi-eye"></i>
    </a>
    @if($canModify && !$isActive)
        <form action="{{ route('workshops.reset-status', $workshop) }}" method="POST" class="d-inline-flex"
              data-confirm="Сбросить статус?" data-submit-guard>
            @csrf @method('PATCH')
            <button type="submit" class="btn btn-outline-warning d-inline-flex align-items-center justify-content-center"
                    style="{{ $btnStyle }}" title="Сбросить статус">
                <i class="bi bi-arrow-counterclockwise"></i>
            </button>
        </form>
    @endif
    @if($canModify && $isActive)
        <form action="{{ route('workshops.destroy', $workshop) }}" method="POST" class="d-inline-flex"
              data-confirm="Удалить операцию?" data-submit-guard>
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-outline-danger d-inline-flex align-items-center justify-content-center"
                    style="{{ $btnStyle }}" title="Удалить">
                <i class="bi bi-trash"></i>
            </button>
        </form>
    @endif
</div>
