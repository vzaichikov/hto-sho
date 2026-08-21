<x-layouts.app title="Події">
    <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 sm:py-12">
        <x-flash-messages />

        <div class="mt-6 flex items-end justify-between gap-5">
            <div>
                <p class="text-sm font-bold text-orange">Ваші закупи</p>
                <h1 class="mt-1 text-4xl font-bold tracking-[-0.045em] sm:text-5xl">Події</h1>
            </div>
            <form method="POST" action="{{ route('events.store') }}">
                @csrf
                <button class="inline-flex items-center rounded-2xl bg-ink px-5 py-3.5 text-sm font-bold text-white shadow-[4px_4px_0_#cdff41] transition hover:-translate-y-0.5" type="submit">
                    <span class="mr-2 text-xl leading-none text-lime">+</span>
                    Нова подія
                </button>
            </form>
        </div>

        @if ($events->isEmpty())
            <div class="mt-12 rounded-[30px] border-2 border-dashed border-ink/20 bg-white/60 px-6 py-16 text-center">
                <div class="mx-auto grid size-16 rotate-[-5deg] place-items-center rounded-2xl bg-lime text-3xl font-bold">?</div>
                <h2 class="mt-6 text-2xl font-bold">З чого почнемо?</h2>
                <p class="mx-auto mt-2 max-w-md text-muted">Створіть подію та одразу додайте переписку або скриншоти. Назву придумаємо автоматично.</p>
                <form class="mt-7" method="POST" action="{{ route('events.store') }}">
                    @csrf
                    <button class="rounded-2xl bg-ink px-6 py-4 font-bold text-white shadow-[5px_5px_0_#ff7d3d]" type="submit">Створити першу подію</button>
                </form>
            </div>
        @else
            <div class="mt-9 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($events as $event)
                    @php
                        $participantCount = $event->people_count ?? count($event->state['participants'] ?? []);
                        $shownTotal = $event->estimated_total ?? $event->budget_amount;
                    @endphp
                    <a class="group flex min-h-56 flex-col rounded-[26px] border border-ink/10 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:border-ink/25 hover:shadow-lg" href="{{ route('events.show', $event) }}">
                        <div class="flex items-start justify-between gap-4">
                            <div class="grid size-11 place-items-center rounded-2xl bg-canvas text-lg font-bold transition group-hover:bg-lime">{{ $loop->iteration }}</div>
                            <x-status-badge :status="$event->status" />
                        </div>
                        <h2 class="mt-6 text-xl font-bold tracking-tight">{{ $event->title }}</h2>
                        <p class="mt-1 text-sm text-muted">Оновлено {{ $event->updated_at->diffForHumans() }}</p>

                        <div class="mt-auto flex items-end justify-between border-t border-ink/10 pt-5">
                            <div>
                                <p class="text-xs font-semibold text-muted">Очікується людей</p>
                                <p class="mt-0.5 font-bold">{{ $participantCount ?: '—' }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-xs font-semibold text-muted">{{ $event->estimated_total ? 'Орієнтовно' : 'Бюджет' }}</p>
                                <p class="mt-0.5 font-bold">{{ $shownTotal ? \Illuminate\Support\Number::currency($shownTotal, in: $event->currency, locale: 'uk') : '—' }}</p>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="mt-8">{{ $events->links() }}</div>
        @endif
    </div>
</x-layouts.app>
