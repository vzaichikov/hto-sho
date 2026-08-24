@php
    $json = fn (mixed $payload): string => json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ) ?: '{}';
    $kindClasses = [
        'action' => 'bg-green-soft text-green-dark',
        'question' => 'bg-yellow text-ink',
        'answer' => 'bg-orange/15 text-orange-dark',
        'llm' => 'bg-beet/10 text-beet',
        'mcp' => 'bg-green/10 text-green-dark',
        'error' => 'bg-orange text-white',
    ];
@endphp

<x-layouts.app :title="'Журнал · '.$event->title">
    <main class="mx-auto max-w-6xl px-4 py-6 sm:px-6 sm:py-10">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <a class="inline-flex -rotate-1 items-center gap-2 rounded-sm bg-yellow/60 px-3 py-1.5 font-display text-lg transition hover:rotate-0 hover:bg-yellow focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-green" href="{{ route('events.show', $event) }}">
                    <span aria-hidden="true">←</span> До події
                </a>
                <p class="mt-6 text-xs font-bold uppercase tracking-[0.16em] text-green-dark">Приватна діагностика</p>
                <h1 class="mt-1 font-display text-4xl leading-[1.1] sm:text-5xl">Журнал Гуся</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Тут Гусь лишає сліди своїх пригод у «{{ $event->title }}»: що почув, що вирішив і де перечепився. Секрети тримає під крилом.</p>
            </div>
            <span class="w-fit rounded-full border-2 border-ink bg-paper px-4 py-2 text-sm font-extrabold shadow-[2px_2px_0_#F7C84B]">Зберігання: 90 днів</span>
        </div>

        <nav class="mt-7 flex flex-wrap gap-2" aria-label="Типи запусків">
            <a class="rounded-full border-2 px-4 py-2 text-sm font-extrabold transition {{ $selectedType === null ? 'border-ink bg-yellow text-ink' : 'border-ink/15 bg-paper text-muted hover:border-green hover:text-ink' }}" href="{{ route('events.journal.index', $event) }}">Усі</a>
            @foreach ($types as $type)
                <a class="rounded-full border-2 px-4 py-2 text-sm font-extrabold transition {{ $selectedType === $type ? 'border-ink bg-yellow text-ink' : 'border-ink/15 bg-paper text-muted hover:border-green hover:text-ink' }}" href="{{ route('events.journal.index', ['event' => $event, 'type' => $type->value]) }}">{{ $type->label() }}</a>
            @endforeach
        </nav>

        @if ($runs->isEmpty())
            <section class="mt-7 rounded-[28px] border-2 border-dashed border-green/40 bg-green-soft/20 p-7 text-center">
                <p class="font-display text-3xl text-green-dark">Поки тихо</p>
                <p class="mt-2 text-sm text-muted">Нові AI та MCP-запуски зʼявляться тут автоматично. Старі пригоди Гуся заднім числом, на жаль, не телепортуються.</p>
            </section>
        @else
            <div class="mt-7 space-y-6">
                @foreach ($runs as $run)
                    <article class="overflow-hidden rounded-[28px] border-2 border-ink bg-paper shadow-[5px_6px_0_#20201D]">
                        <header class="flex flex-col gap-3 border-b-2 border-ink/10 bg-yellow/25 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="font-display text-2xl">{{ $run->type->label() }}</h2>
                                    <span class="rounded-full bg-paper px-2.5 py-1 text-xs font-extrabold">{{ $run->status->label() }}</span>
                                    <span class="rounded-full bg-ink/5 px-2.5 py-1 font-mono text-[11px]">#{{ $run->id }}</span>
                                </div>
                                <p class="mt-1 break-all font-mono text-[11px] text-muted">{{ $run->correlation_id }}</p>
                            </div>
                            <time class="text-xs font-bold text-muted" datetime="{{ $run->created_at->toISOString() }}">{{ $run->created_at->format('d.m.Y H:i:s') }}</time>
                        </header>

                        @if ($run->error)
                            <div class="border-b border-orange/30 bg-orange/10 px-5 py-4 text-sm text-orange-dark">{{ $run->error }}</div>
                        @endif

                        <ol class="divide-y divide-ink/10">
                            @foreach ($run->entries as $entry)
                                <li class="px-5 py-5 sm:px-6">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="rounded-full px-2.5 py-1 text-[11px] font-black uppercase tracking-wide {{ $kindClasses[$entry->kind->value] ?? 'bg-ink/5 text-ink' }}">{{ $entry->kind->label() }}</span>
                                                <span class="font-mono text-[11px] text-muted">{{ $entry->sequence }}</span>
                                                @if ($entry->status !== 'completed')
                                                    <span class="rounded-full bg-orange/10 px-2 py-1 text-[11px] font-bold text-orange-dark">{{ $entry->status }}</span>
                                                @endif
                                            </div>
                                            <h3 class="mt-2 font-display text-xl leading-tight">{{ $entry->title }}</h3>
                                            @if ($entry->message)
                                                <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-muted">{{ $entry->message }}</p>
                                            @endif
                                        </div>
                                        <time class="shrink-0 text-xs text-muted" datetime="{{ $entry->created_at->toISOString() }}">{{ $entry->created_at->format('H:i:s') }}</time>
                                    </div>

                                    @if ($entry->endpoint || $entry->method || $entry->status_code || $entry->duration_ms !== null)
                                        <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 font-mono text-[11px] text-muted">
                                            @if ($entry->method)<span>{{ $entry->method }}</span>@endif
                                            @if ($entry->endpoint)<span class="break-all">{{ $entry->endpoint }}</span>@endif
                                            @if ($entry->status_code)<span>HTTP {{ $entry->status_code }}</span>@endif
                                            @if ($entry->duration_ms !== null)<span>{{ $entry->duration_ms }} ms</span>@endif
                                        </div>
                                    @endif

                                    @foreach ([
                                        'Payload запиту' => $entry->request_payload,
                                        'Payload відповіді' => $entry->response_payload,
                                        'Метадані' => $entry->metadata,
                                    ] as $payloadLabel => $payload)
                                        @if ($payload !== null)
                                            <details class="mt-3 rounded-2xl border border-ink/15 bg-canvas">
                                                <summary class="cursor-pointer px-4 py-3 text-sm font-extrabold focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-green">{{ $payloadLabel }}</summary>
                                                <pre class="max-h-[38rem] overflow-auto border-t border-ink/10 p-4 text-xs leading-5"><code>{{ $json($payload) }}</code></pre>
                                            </details>
                                        @endif
                                    @endforeach
                                </li>
                            @endforeach
                        </ol>
                    </article>
                @endforeach
            </div>

            <div class="mt-8">{{ $runs->links() }}</div>
        @endif
    </main>
</x-layouts.app>
