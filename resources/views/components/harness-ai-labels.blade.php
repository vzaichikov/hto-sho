@php
    $configuredProvider = (string) config('services.ai.provider');
    $provider = match ($configuredProvider) {
        'openai' => 'OpenAI',
        'ollama' => 'Ollama',
        default => \Illuminate\Support\Str::headline($configuredProvider),
    };
    $models = collect([
        'Модель' => (string) config('services.ai.model'),
        'Лексика' => (string) config('services.ai.lexical_model'),
    ])->filter(fn (string $model): bool => filled($model));
@endphp

<dl
    {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-1.5 text-[0.65rem] leading-none']) }}
    data-harness-ai-labels
>
    <div class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-ink/10 bg-paper/80 px-2 py-1.5">
        <dt class="shrink-0 text-[0.55rem] font-extrabold uppercase tracking-[0.12em] text-muted">Провайдер</dt>
        <dd class="min-w-0 break-all font-extrabold text-ink" data-harness-ai-provider>{{ $provider }}</dd>
    </div>

    @foreach ($models as $label => $model)
        <div class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-ink/10 bg-paper/80 px-2 py-1.5">
            <dt class="shrink-0 text-[0.55rem] font-extrabold uppercase tracking-[0.12em] text-muted">{{ $label }}</dt>
            <dd class="min-w-0 break-all font-extrabold text-ink" data-harness-ai-model>{{ $model }}</dd>
        </div>
    @endforeach
</dl>
