@props(['status'])

@php
    $statusValue = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $statusLabel = \App\EventStatus::tryFrom($statusValue)?->label() ?? $statusValue;
    $statusClasses = match ($statusValue) {
        'processing' => 'border-yellow bg-yellow/35 text-ink',
        'ready' => 'border-green/25 bg-green-soft text-green-dark',
        'failed' => 'border-orange/25 bg-orange/10 text-orange-dark',
        default => 'border-ink/10 bg-paper text-muted',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-full border px-3 py-1 text-xs font-extrabold', $statusClasses]) }}>
    @if ($statusValue === 'processing')
        <span class="mr-1.5 size-1.5 animate-pulse rounded-full bg-current"></span>
    @endif
    {{ $statusLabel }}
</span>
