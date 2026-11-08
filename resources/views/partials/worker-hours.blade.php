{{-- Часы ремонтных работ за выбранный период (App\Services\WorkerHourService).
     Переменные дашборда: $worker, $hourEntries, $totalHours, $totalHoursPay, $canSeePay,
     $canAddHours, $hourlyRate, $hoursUnpaidFrom, $hoursOpen. Суммы — только тем, кому видны деньги ($canSeePay).
     Блок виден всегда, и без записей: админ и мастер на чужой странице должны видеть, что часов нет.
     Свёрнут, если за текущую неделю часов нет ($hoursOpen) и форма не вернулась с ошибкой. --}}
@php
    $hoursFmt  = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',') . ' ч';
    $rubFmt    = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' ₽';
    $hoursErr  = $errors->hasAny(['work_date', 'hours', 'comment']);
    $isOpen    = $hoursOpen || $hoursErr;
@endphp

<div class="card shadow-sm mb-3 wd-section wd-section--hours" id="worker-hours">
    <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center gap-2"
         style="cursor:pointer" id="worker-hours-toggle" role="button" aria-expanded="{{ $isOpen ? 'true' : 'false' }}">
        <span class="fw-semibold"><i class="bi bi-tools me-1"></i>Ремонтные работы</span>
        <span class="d-flex align-items-center gap-2">
            @if($hourEntries->isNotEmpty())
                <span class="small text-muted text-nowrap">
                    {{ $hoursFmt($totalHours) }}
                    @if($canSeePay) · <span class="text-success">{{ $rubFmt($totalHoursPay) }}</span> @endif
                </span>
            @endif
            <i class="bi {{ $isOpen ? 'bi-chevron-up' : 'bi-chevron-down' }}" id="worker-hours-chevron"></i>
        </span>
    </div>

    <div id="worker-hours-body" @unless($isOpen) style="display:none" @endunless>
        @if($hourEntries->isEmpty())
            <div class="card-body py-2 px-3 small text-muted wd-part wd-part--note">За период часов нет</div>
        @else
            <ul class="list-group list-group-flush wd-part wd-part--list">
                @foreach($hourEntries as $entry)
                    {{-- baseline: дата и сумма мелким шрифтом стоят на одной линии с часами --}}
                    <li class="list-group-item px-3 py-2 d-flex align-items-baseline gap-2">
                        <div class="text-nowrap small fw-semibold" style="min-width:2.6rem">
                            {{ $entry->work_date->format('d.m') }}
                        </div>
                        <div class="flex-grow-1" style="min-width:0">
                            <div class="fw-semibold">{{ $hoursFmt($entry->hours) }}
                                @if($canSeePay)
                                    <span class="small text-muted fw-normal">× {{ $rubFmt($entry->hourly_rate) }}/ч</span>
                                @endif
                            </div>
                            @if($entry->comment)
                                <div class="small text-muted text-break">{{ $entry->comment }}</div>
                            @endif
                        </div>
                        @if($canSeePay)
                            <div class="text-success small fw-semibold text-nowrap">{{ $rubFmt($entry->amount) }}</div>
                        @endif
                        @can('modify', $entry)
                            {{-- Кнопки одного размера: <a> и <button> в форме иначе расходятся по высоте --}}
                            <div class="d-flex gap-1 align-items-center">
                                <a href="{{ route('worker-hours.edit', $entry) }}" class="btn btn-sm btn-outline-secondary wd-icon-btn" title="Изменить">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form method="POST" action="{{ route('worker-hours.destroy', $entry) }}" data-submit-guard
                                      class="m-0 d-flex" data-confirm="Удалить запись {{ $hoursFmt($entry->hours) }} за {{ $entry->work_date->format('d.m') }}?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger wd-icon-btn" title="Удалить">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif

        @if($canAddHours)
            <details class="card-body py-2 px-3 border-top wd-part wd-part--more" @if($hoursErr) open @endif>
                <summary class="small fw-semibold text-primary" style="cursor:pointer">
                    <i class="bi bi-plus-circle me-1"></i>Добавить часы
                </summary>
                <form method="POST" action="{{ route('worker-hours.store', $worker) }}" data-submit-guard class="mt-2">
                    @csrf
                    <div class="row g-2">
                        <div class="col-7">
                            <label class="form-label small mb-1" for="hours_work_date">Дата работы</label>
                            <input type="date" name="work_date" id="hours_work_date"
                                   class="form-control @error('work_date') is-invalid @enderror"
                                   value="{{ old('work_date', now()->toDateString()) }}"
                                   @if($hoursUnpaidFrom) min="{{ $hoursUnpaidFrom->toDateString() }}" @endif max="{{ now()->toDateString() }}" required>
                            @error('work_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-5">
                            <label class="form-label small mb-1" for="hours_hours">Часы</label>
                            <input type="number" name="hours" id="hours_hours" inputmode="decimal"
                                   class="form-control @error('hours') is-invalid @enderror"
                                   value="{{ old('hours') }}" min="0.25" max="24" step="0.25" required>
                            @error('hours')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-1" for="hours_comment">Что делали <span class="text-muted">(необязательно)</span></label>
                            <input type="text" name="comment" id="hours_comment" maxlength="255"
                                   class="form-control @error('comment') is-invalid @enderror"
                                   value="{{ old('comment') }}" placeholder="Например: замена ленты на станке">
                            @error('comment')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center gap-2 mt-2">
                        <span class="small text-muted">
                            @if($hourlyRate > 0)
                                Ставка {{ $rubFmt($hourlyRate) }}/ч
                            @else
                                Ставка за час не задана — часы учитываются без оплаты
                            @endif
                        </span>
                        <button type="submit" class="btn btn-primary btn-sm">Добавить</button>
                    </div>
                </form>
            </details>
        @endif
    </div>
</div>

@push('styles')
    <style>
        #worker-hours .wd-icon-btn {
            width: 1.9rem; height: 1.9rem; padding: 0;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: .85rem; line-height: 1;
        }
    </style>
@endpush

@push('scripts')
    <script>
        // Свернуть / раскрыть блок часов по клику на шапку (состояние не запоминается:
        // раскрыт, только когда за текущую неделю есть часы)
        document.addEventListener('DOMContentLoaded', function () {
            const toggle  = document.getElementById('worker-hours-toggle');
            const body    = document.getElementById('worker-hours-body');
            const chevron = document.getElementById('worker-hours-chevron');
            if (!toggle) return;
            toggle.addEventListener('click', function () {
                const open = body.style.display === 'none';
                body.style.display = open ? '' : 'none';
                chevron.className  = open ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        });
    </script>
@endpush
