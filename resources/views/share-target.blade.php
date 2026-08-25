<x-layouts.app title="Поділитися картинками">
    <div
        class="mx-auto max-w-6xl px-4 py-7 sm:px-6 sm:py-10"
        data-share-target
        data-discard-url="{{ auth()->check() ? route('events.index') : route('landing') }}"
        data-discard-session-url="{{ route('share-target.discard') }}"
    >
        <x-flash-messages />

        <section class="overflow-hidden rounded-[32px] border-2 border-ink bg-paper shadow-[7px_8px_0_#20201D]">
            <div class="relative overflow-hidden border-b-2 border-ink bg-yellow/35 px-6 py-7 pr-32 sm:px-9 sm:py-9 sm:pr-56">
                <p class="font-display text-lg leading-[1.15] text-green-dark">Гусь уже підхопив картинки</p>
                <h1 class="mt-2 max-w-2xl font-display text-4xl leading-[1.1] tracking-[-0.035em] sm:text-5xl">У яку подію їх додати?</h1>
                <p class="mt-3 max-w-xl text-sm leading-6 text-muted sm:text-base">Спершу перевірте файли, потім оберіть подію. До цього моменту вони лишаються тільки у вашому браузері.</p>
                <img class="absolute -bottom-28 right-2 w-36 drop-shadow-xl sm:-bottom-44 sm:right-7 sm:w-56" src="{{ asset('images/brand/goose-sho.png') }}" alt="" aria-hidden="true">
            </div>

            <div class="p-5 sm:p-8">
                <div class="rounded-[24px] border-2 border-dashed border-green/45 bg-green-soft/15 p-5" data-share-loading aria-live="polite">
                    <p class="font-extrabold">Гусь звіряє, що прилетіло…</p>
                </div>

                <div class="hidden" data-share-content>
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="font-display text-2xl leading-[1.15]">Картинки до події</p>
                            <p class="mt-1 text-sm text-muted" data-share-summary></p>
                        </div>
                        <button class="self-start rounded-full border-2 border-ink bg-paper px-4 py-2 text-xs font-extrabold transition hover:bg-yellow/35 focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-green" type="button" data-share-discard>Не додавати</button>
                    </div>
                    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-5" data-share-previews></div>
                    <p class="mt-4 hidden rounded-2xl border border-orange/30 bg-orange/10 px-4 py-3 text-sm font-semibold text-orange-dark" role="alert" data-share-status></p>

                    @guest
                        <div class="mt-7 rounded-[24px] border-2 border-ink bg-canvas p-5 sm:p-6">
                            <h2 class="font-display text-3xl leading-[1.1]">Спершу впізнаємо господаря</h2>
                            <p class="mt-2 max-w-xl text-sm leading-6 text-muted">Увійдіть через Сільпо — і Гусь поверне вас сюди до цих самих картинок.</p>
                            <a class="mt-5 inline-flex rounded-2xl bg-orange px-6 py-4 font-extrabold text-white shadow-[4px_4px_0_#F7C84B] transition hover:-translate-y-0.5 hover:bg-orange-dark" href="{{ route('mcp.oauth.silpo.connect') }}">Увійти через Сільпо</a>
                        </div>
                    @else
                        @if ($events->isEmpty())
                            <div class="mt-7 rounded-[24px] border-2 border-dashed border-green/50 bg-canvas p-6 text-center">
                                <h2 class="font-display text-3xl leading-[1.1]">Спершу створимо подію</h2>
                                <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-muted">Картинки почекають тут, поки ви дасте пригоді назву й короткий задум.</p>
                                <a class="mt-5 inline-flex rounded-2xl bg-orange px-6 py-4 font-extrabold text-white shadow-[4px_4px_0_#F7C84B] transition hover:-translate-y-0.5 hover:bg-orange-dark" href="{{ route('events.create') }}">Створити подію</a>
                            </div>
                        @else
                            <div class="mt-8">
                                <p class="font-display text-lg leading-[1.15] text-green-dark">Останній крок</p>
                                <h2 class="mt-1 font-display text-3xl leading-[1.1]">Оберіть подію</h2>
                                <div class="mt-5 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                                    @foreach ($events as $event)
                                        <button
                                            class="group flex min-h-40 flex-col rounded-[24px] border-2 border-ink/15 bg-canvas p-5 text-left transition hover:-translate-y-1 hover:border-ink hover:shadow-[4px_5px_0_#F24B35] disabled:cursor-wait disabled:opacity-55 focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-green"
                                            type="button"
                                            data-share-event
                                            data-upload-url="{{ route('events.sources.store', $event) }}"
                                        >
                                            <span class="text-xs font-extrabold uppercase tracking-[0.12em] text-green-dark">Матеріалів: {{ $event->sources_count }}</span>
                                            <span class="mt-4 font-display text-2xl leading-[1.15]">{{ $event->title }}</span>
                                            <span class="mt-auto pt-4 text-sm font-extrabold text-orange-dark" data-share-event-label>Додати сюди <span aria-hidden="true">→</span></span>
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endguest
                </div>

                <div class="hidden rounded-[24px] border-2 border-dashed border-ink/20 bg-canvas p-7 text-center" data-share-empty>
                    <div class="mx-auto grid size-14 -rotate-3 place-items-center rounded-[45%] bg-yellow font-display text-3xl" aria-hidden="true">?</div>
                    <h2 class="mt-5 font-display text-3xl leading-[1.1]">Нових картинок немає</h2>
                    <p class="mx-auto mt-2 max-w-lg text-sm leading-6 text-muted" data-share-empty-message>Схоже, браузер відкрив Гуся без пакунка. Поверніться до застосунку, з якого хотіли поділитися, й спробуйте ще раз.</p>
                    <a class="mt-5 inline-flex rounded-2xl border-2 border-ink bg-paper px-5 py-3 font-extrabold transition hover:bg-yellow/35" href="{{ auth()->check() ? route('events.index') : route('landing') }}">До Хто Шо?</a>
                </div>
            </div>
        </section>
    </div>
</x-layouts.app>
