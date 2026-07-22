{{-- Строка партии сырья без приёмок в разделе «По партиям» (десктоп) --}}
@php
    $bInit = (float) ($batch->initial_quantity ?? 0);
    $bRem  = (float) ($batch->remaining_quantity ?? 0);
@endphp
<tr class="table-info">
    <td>{{ $batch->batch_number ?? '—' }}</td>
    <td class="text-nowrap">{{ $batch->created_at->format('d.m.Y H:i') }}</td>
    <td><span class="text-muted fst-italic">приёмок нет</span></td>
    <td class="text-muted">—</td>
    <td>
        <a href="{{ route('raw-batches.show', $batch) }}">
            {{ $batch->product->name ?? '?' }}
        </a>
        <br>
        <div class="d-flex gap-1 mt-1">
            <span title="Всего в партии" style="font-size:.68rem;padding:1px 5px;border-radius:3px;background:#e9ecef;color:#495057;white-space:nowrap">{{ number_format($bInit, 3, '.', '') }}</span>
            <span title="Доступно в партии" style="font-size:.68rem;padding:1px 5px;border-radius:3px;background:{{ $bRem > 0 ? '#cff4fc' : '#fff3cd' }};color:{{ $bRem > 0 ? '#055160' : '#664d03' }};white-space:nowrap">{{ number_format($bRem, 3, '.', '') }}</span>
        </div>
    </td>
    <td class="text-muted">—</td>
    <td class="text-muted">—</td>
    <td>{{ $batch->currentWorker->name ?? '—' }}</td>
    <td>{{ $batch->currentStore->name ?? '—' }}</td>
    <td class="small text-muted">{{ $batch->department?->name ?? '—' }}</td>
    <td><span class="badge {{ $batch->statusBadgeClass() }}">{{ $batch->statusLabel() }}</span></td>
    <td>
        <div class="d-flex gap-1 justify-content-end">
            <a href="{{ route('stone-receptions.create', ['cutter_id' => $batch->current_worker_id, 'raw_material_batch_id' => $batch->id]) }}"
               class="btn btn-sm btn-success" title="Оформить приёмку">
                <i class="bi bi-plus-lg"></i>
            </a>
            <a href="{{ route('raw-batches.show', $batch) }}"
               class="btn btn-sm btn-outline-secondary" title="Просмотр партии">
                <i class="bi bi-eye"></i>
            </a>
        </div>
    </td>
</tr>
