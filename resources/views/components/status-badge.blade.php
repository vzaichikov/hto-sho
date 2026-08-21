@props(['status'])

@php
    $statusValue = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $statusLabel = \App\EventStatus::tryFrom($statusValue)?->label() ?? $statusValue;
    $statusClasses = match ($statusValue) {
        'processing' => 'bg-orange/15 text-orange-dark',
        'ready' => 'bg-lime/40 text-ink',
        'failed' => 'bg-red-100 text-red-700',
        default => 'bg-ink/7 text-muted',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-full px-3 py-1 text-xs font-bold', $statusClasses]) }}>
    @if ($statusValue === 'processing')
        <span class="mr-1.5 size-1.5 animate-pulse rounded-full bg-current"></span>
    @endif
    {{ $statusLabel }}
</span>
