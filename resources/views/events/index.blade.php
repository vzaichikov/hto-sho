<x-layouts.app title="Події">
    <div class="mx-auto max-w-7xl px-4 py-7 sm:px-6 sm:py-10">
        <x-flash-messages />

        <section class="relative mt-5 overflow-hidden rounded-[32px] border-2 border-ink bg-paper px-6 py-8 shadow-[7px_8px_0_#20201D] sm:px-9 sm:py-10">
            <span class="inline-block -rotate-2 rounded-sm bg-yellow px-3 py-1.5 font-display text-lg">Гусь усе тримає під контролем</span>
            <div class="relative z-10 mt-5 max-w-xl sm:max-w-[65%]">
                <h1 class="font-display text-5xl leading-[1.1] tracking-[-0.035em] sm:text-6xl">Ваші смачні плани</h1>
                <p class="mt-4 max-w-lg text-sm leading-6 text-muted sm:text-base sm:leading-7">Створюйте подію, додавайте уривки переписки — і повертайтеся до одного актуального списку без перечитування чату.</p>

                <div class="mt-6">
                    <a class="inline-flex items-center gap-3 rounded-2xl bg-orange px-5 py-3.5 text-sm font-extrabold text-white shadow-[4px_4px_0_#F7C84B] transition hover:-translate-y-0.5 hover:bg-orange-dark" href="{{ route('events.create') }}">
                        <span class="grid size-7 place-items-center rounded-full bg-white/20 text-xl leading-none">+</span>
                        Нова подія
                    </a>
                </div>
            </div>

            <span class="absolute right-36 top-7 hidden rotate-12 font-display text-5xl text-green lg:block" aria-hidden="true">♡</span>
            <img class="absolute -bottom-24 right-2 hidden w-52 drop-shadow-xl sm:block lg:right-10 lg:w-60" src="{{ asset('images/brand/goose-sho.png') }}" alt="" aria-hidden="true">
        </section>

        @if ($events->isEmpty())
            <div class="mt-10 rounded-[30px] border-2 border-dashed border-green/50 bg-paper/75 px-6 py-14 text-center">
                <div class="mx-auto grid size-16 -rotate-6 place-items-center rounded-[45%] bg-yellow font-display text-4xl">?</div>
                <h2 class="mt-6 font-display text-3xl">З чого почнемо?</h2>
                <p class="mx-auto mt-2 max-w-md text-muted">Назвіть задум у двох коротких кроках. Гусь перевірить напрям і одразу почне збирати перший контекст.</p>
                <a class="mt-7 inline-flex rounded-2xl bg-orange px-6 py-4 font-extrabold text-white shadow-[4px_4px_0_#F7C84B] transition hover:-translate-y-0.5 hover:bg-orange-dark" href="{{ route('events.create') }}">Створити першу подію</a>
            </div>
        @else
            <div class="mt-11 flex items-end justify-between gap-4">
                <div>
                    <p class="font-display text-lg leading-[1.15] text-green-dark">Усе в одному місці</p>
                    <h2 class="mt-1 font-display text-4xl">Ваші події</h2>
                </div>
                <span class="-rotate-2 rounded-sm bg-yellow/70 px-3 py-1 font-display text-lg">{{ $events->total() }} подій</span>
            </div>

            <div class="mt-6 grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($events as $event)
                    @php
                        $participantCount = $event->people_count ?? count($event->state['participants'] ?? []);
                        $shownTotal = $event->estimated_total ?? $event->budget_amount;
                    @endphp
                    <a class="group flex min-h-60 flex-col rounded-[26px] border-2 border-ink/10 bg-paper p-5 shadow-[3px_4px_0_rgba(32,32,29,0.08)] transition hover:-translate-y-1 hover:border-ink hover:shadow-[5px_6px_0_#F24B35]" href="{{ route('events.show', $event) }}">
                        <div class="flex items-start justify-between gap-4">
                            <div class="grid size-11 -rotate-3 place-items-center rounded-[45%] bg-yellow font-display text-xl transition group-hover:rotate-0">{{ $loop->iteration }}</div>
                            <x-status-badge :status="$event->status" />
                        </div>
                        <h3 class="mt-6 font-display text-2xl leading-[1.15] tracking-tight transition group-hover:text-orange-dark">{{ $event->title }}</h3>
                        <p class="mt-1 text-sm text-muted">Оновлено {{ $event->updated_at->diffForHumans() }} · матеріалів: {{ $event->sources_count }}</p>

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
                        <span class="mt-4 text-sm font-extrabold text-orange-dark">Відкрити план <span aria-hidden="true">→</span></span>
                    </a>
                @endforeach
            </div>

            <div class="mt-8">{{ $events->links() }}</div>
        @endif
    </div>
</x-layouts.app>
