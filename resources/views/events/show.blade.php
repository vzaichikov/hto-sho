@php
    $state = $event->state ?? [];
    $participants = $state['participants'] ?? [];
    $needs = $state['shopping_needs'] ?? $state['needs'] ?? [];
    $warnings = $state['warnings'] ?? [];
    $agreements = $state['agreements'] ?? [];
    $plan = $event->shopping_plan ?? [];
    $planItems = $plan['items'] ?? [];
    $unresolvedItems = $plan['unresolved_items'] ?? [];
    $checkoutUrl = $plan['checkout_url'] ?? null;
@endphp

<x-layouts.app :title="$event->title">
    <div
        class="mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-10"
        data-event-workspace
        data-event-status-url="{{ route('events.status', $event) }}"
        data-event-status="{{ $event->status->value }}"
        data-event-state-version="{{ $event->state_version }}"
        data-event-updated-at="{{ $event->updated_at->toISOString() }}"
    >
        <a class="inline-flex -rotate-1 items-center gap-2 rounded-sm bg-yellow/60 px-3 py-1.5 font-display text-lg transition hover:rotate-0 hover:bg-yellow" href="{{ route('events.index') }}">
            <span aria-hidden="true">←</span> До всіх подій
        </a>

        <x-flash-messages class="mt-4" />

        <header class="mt-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="truncate font-display text-4xl leading-none tracking-[-0.035em] sm:text-5xl">{{ $event->title }}</h1>
                    <x-status-badge :status="$event->status" data-event-status-badge />
                </div>
                <p class="mt-2 text-sm text-muted">Версія даних: {{ $event->state_version }} · Оновлено {{ $event->updated_at->diffForHumans() }}</p>
                @if ($event->description || $event->people_count || $event->budget_amount)
                    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted">
                        @if ($event->description)<span>{{ $event->description }}</span>@endif
                        @if ($event->people_count)<span class="font-semibold text-ink">{{ $event->people_count }} люд.</span>@endif
                        @if ($event->budget_amount)<span class="font-semibold text-ink">до {{ \Illuminate\Support\Number::currency($event->budget_amount, in: $event->currency, locale: 'uk') }}</span>@endif
                    </div>
                @endif
            </div>

            <details class="relative shrink-0">
                <summary class="cursor-pointer list-none rounded-full border-2 border-ink bg-paper px-4 py-2 text-sm font-extrabold shadow-[2px_2px_0_#F7C84B] transition hover:-translate-y-0.5 hover:bg-yellow/30">Налаштування</summary>
                <div class="absolute right-0 z-20 mt-3 w-[min(22rem,calc(100vw-2rem))] rounded-2xl border-2 border-ink bg-paper p-4 shadow-[5px_6px_0_#20201D]">
                    <form method="POST" action="{{ route('events.update', $event) }}">
                        @csrf
                        @method('PATCH')
                        <label class="text-xs font-bold text-muted" for="title">Назва події</label>
                        <input class="mt-2 w-full rounded-xl border border-ink/20 bg-canvas px-3 py-2.5 text-sm outline-none focus:border-green focus:ring-3 focus:ring-green/15" id="title" name="title" maxlength="120" required value="{{ $event->title }}">
                        <label class="mt-4 block text-xs font-bold text-muted" for="description">Короткий опис</label>
                        <textarea class="mt-2 min-h-20 w-full resize-y rounded-xl border border-ink/20 bg-canvas px-3 py-2.5 text-sm outline-none focus:border-green focus:ring-3 focus:ring-green/15" id="description" name="description" maxlength="1000" placeholder="Пікнік у парку, без складного готування">{{ $event->description }}</textarea>
                        <div class="mt-4 grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-bold text-muted" for="people_count">Кількість людей</label>
                                <input class="mt-2 w-full rounded-xl border border-ink/20 bg-canvas px-3 py-2.5 text-sm outline-none focus:border-green focus:ring-3 focus:ring-green/15" id="people_count" name="people_count" type="number" min="1" max="10000" value="{{ $event->people_count }}" placeholder="Ще невідомо">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-muted" for="budget_amount">Бюджет, ₴</label>
                                <input class="mt-2 w-full rounded-xl border border-ink/20 bg-canvas px-3 py-2.5 text-sm outline-none focus:border-green focus:ring-3 focus:ring-green/15" id="budget_amount" name="budget_amount" type="number" min="0" step="0.01" value="{{ $event->budget_amount }}" placeholder="Не задано">
                            </div>
                        </div>
                        <p class="mt-2 text-xs leading-5 text-muted">Кількість можна не вказувати — спробуємо визначити її з переписки.</p>
                        <button class="mt-3 w-full rounded-xl bg-orange px-4 py-2.5 text-sm font-extrabold text-white transition hover:bg-orange-dark" type="submit">Зберегти контекст</button>
                    </form>

                    <form class="mt-4 border-t border-ink/10 pt-4" method="POST" action="{{ route('events.destroy', $event) }}" data-confirm="Видалити подію разом з усіма скриншотами?">
                        @csrf
                        @method('DELETE')
                        <button class="text-sm font-bold text-orange-dark hover:text-beet" type="submit">Видалити подію</button>
                    </form>
                </div>
            </details>
        </header>

        <section class="mt-8 overflow-hidden rounded-[30px] border-2 border-ink bg-paper shadow-[7px_8px_0_#20201D]" id="composer">
            <div class="relative min-h-28 overflow-hidden border-b-2 border-ink/10 bg-yellow/35 px-5 py-5 pr-28 sm:px-7 sm:pr-40">
                <p class="font-display text-lg leading-none text-green-dark">Гусь чекає на новини</p>
                <h2 class="mt-1 font-display text-3xl leading-none">Вставте переписку або скриншоти</h2>
                <p class="mt-2 text-xs leading-5 text-muted sm:text-sm">Хоч усе одразу, хоч по одному уточненню.</p>
                <img class="absolute -bottom-24 right-2 w-28 drop-shadow-lg sm:-bottom-32 sm:right-6 sm:w-40" src="{{ asset('images/brand/goose-sho.png') }}" alt="" aria-hidden="true">
            </div>

            <form class="p-5 sm:p-7" method="POST" action="{{ route('events.sources.store', $event) }}" enctype="multipart/form-data" data-source-composer>
                @csrf
                <label class="sr-only" for="source-text">Текст переписки або уточнення</label>
                <textarea class="min-h-40 w-full resize-y rounded-2xl border border-ink/20 bg-canvas p-4 text-base leading-7 outline-none placeholder:text-muted/70 focus:border-green focus:ring-4 focus:ring-green/15" id="source-text" name="text" maxlength="50000" placeholder="Вставте повідомлення з чату або напишіть уточнення: Марина не прийде, а Саша бере напої…">{{ old('text') }}</textarea>

                <div class="mt-4 rounded-2xl border-2 border-dashed border-green/45 bg-green-soft/20 p-5 text-center transition data-[dragging=true]:border-orange data-[dragging=true]:bg-orange/5" data-file-dropzone>
                    <input class="sr-only" id="source-images" name="images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple data-file-input>
                    <label class="cursor-pointer" for="source-images">
                        <span class="mx-auto grid size-11 -rotate-3 place-items-center rounded-[45%] bg-yellow font-display text-2xl transition hover:rotate-0" aria-hidden="true">↥</span>
                        <span class="mt-3 block text-sm font-extrabold">Перетягніть, вставте або виберіть скриншоти</span>
                        <span class="mt-1 block text-xs text-muted">JPG, PNG чи WebP · до 8 МБ · максимум 10 файлів</span>
                    </label>
                    <div class="mt-4 hidden grid-cols-2 gap-3 text-left sm:grid-cols-4" data-file-previews></div>
                </div>

                <button class="mt-5 inline-flex w-full items-center justify-center rounded-2xl bg-orange px-6 py-4 font-extrabold text-white shadow-[4px_4px_0_#F7C84B] transition hover:-translate-y-0.5 hover:bg-orange-dark sm:w-auto" type="submit">
                    Додати й оновити <span class="ml-3 text-yellow" aria-hidden="true">→</span>
                </button>
            </form>
        </section>

        @if ($event->status === \App\EventStatus::Processing)
            <section class="mt-7 rounded-[24px] border border-yellow bg-yellow/25 p-5" aria-live="polite">
                <div class="flex items-start gap-4">
                    <span class="mt-0.5 grid size-10 shrink-0 animate-pulse place-items-center rounded-[45%] bg-yellow font-display text-2xl">…</span>
                    <div>
                        <h2 class="font-bold">Дані додано, готуємо оновлення</h2>
                        <p class="mt-1 text-sm leading-6 text-muted">Сторінка сама покаже новий результат після обробки. У цьому етапі MVP джерела вже збережені, а AI-обробник підключається окремим наступним кроком.</p>
                    </div>
                </div>
            </section>
        @elseif ($event->status === \App\EventStatus::Failed)
            <section class="mt-7 rounded-[24px] border border-orange/30 bg-orange/10 p-5 text-orange-dark">
                <h2 class="font-bold">Не вдалося оновити результат</h2>
                <p class="mt-1 text-sm">{{ $event->analysis_error ?: 'Додайте уточнення або повторіть спробу.' }}</p>
            </section>
        @endif

        @if ($state !== [])
            @if ($warnings !== [])
                <section class="mt-8 rounded-[24px] border-2 border-orange bg-paper p-5 shadow-[5px_5px_0_#F7C84B] sm:p-6">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-orange-dark">Потрібне уточнення</p>
                    <ul class="mt-3 space-y-2 text-sm leading-6">
                        @foreach ($warnings as $warning)
                            <li class="flex gap-2"><span aria-hidden="true">→</span><span>{{ is_array($warning) ? ($warning['message'] ?? json_encode($warning, JSON_UNESCAPED_UNICODE)) : $warning }}</span></li>
                        @endforeach
                    </ul>
                    <a class="mt-4 inline-block text-sm font-bold underline decoration-orange decoration-2 underline-offset-4" href="#composer">Додати відповідь</a>
                </section>
            @endif

            <div class="mt-10 grid gap-8 lg:grid-cols-[1.08fr_0.92fr]">
                <section>
                    <div class="flex items-center gap-3">
                        <span class="grid size-9 -rotate-3 place-items-center rounded-[45%] bg-green font-display text-xl text-white">1</span>
                        <h2 class="font-display text-3xl tracking-tight">Що відомо</h2>
                    </div>

                    @if ($state['summary'] ?? null)
                        <p class="mt-5 text-base leading-7 text-muted">{{ $state['summary'] }}</p>
                    @endif

                    @if ($participants !== [])
                        <div class="mt-5 grid gap-3 sm:grid-cols-2">
                            @foreach ($participants as $participant)
                                <article class="rounded-2xl border border-ink/10 bg-paper p-4 shadow-sm">
                                    <div class="flex items-start justify-between gap-3">
                                        <h3 class="font-bold">{{ $participant['name'] ?? 'Учасник' }}</h3>
                                        @if ($participant['status'] ?? null)
                                            <span class="rounded-full bg-canvas px-2.5 py-1 text-xs font-semibold text-muted">{{ $participant['status'] }}</span>
                                        @endif
                                    </div>
                                    @foreach (['preferences' => 'Подобається', 'restrictions' => 'Обмеження', 'allergies' => 'Алергії', 'drinks' => 'Напої', 'brings' => 'Бере з собою'] as $key => $label)
                                        @php $values = $participant[$key] ?? []; @endphp
                                        @if ($values !== [] && $values !== null)
                                            <p class="mt-2 text-xs leading-5"><span class="font-bold">{{ $label }}:</span> {{ is_array($values) ? implode(', ', $values) : $values }}</p>
                                        @endif
                                    @endforeach
                                </article>
                            @endforeach
                        </div>
                    @endif

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        @if ($event->budget_amount || ($state['budget'] ?? null))
                            <div class="rounded-2xl bg-green-soft/70 p-4">
                                <p class="text-xs font-bold text-muted">БЮДЖЕТ</p>
                                <p class="mt-1 text-xl font-bold">{{ $event->budget_amount ? \Illuminate\Support\Number::currency($event->budget_amount, in: $event->currency, locale: 'uk') : (is_array($state['budget']) ? ($state['budget']['summary'] ?? json_encode($state['budget'], JSON_UNESCAPED_UNICODE)) : $state['budget']) }}</p>
                            </div>
                        @endif
                        @if ($agreements !== [])
                            <div class="rounded-2xl bg-yellow/25 p-4">
                                <p class="text-xs font-bold text-muted">ДОМОВЛЕНОСТІ</p>
                                <ul class="mt-2 space-y-1 text-sm">
                                    @foreach ($agreements as $agreement)
                                        <li>• {{ is_array($agreement) ? ($agreement['summary'] ?? json_encode($agreement, JSON_UNESCAPED_UNICODE)) : $agreement }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                </section>

                <section>
                    <div class="flex items-center gap-3">
                        <span class="grid size-9 rotate-2 place-items-center rounded-[45%] bg-yellow font-display text-xl">2</span>
                        <h2 class="font-display text-3xl tracking-tight">Що треба купити</h2>
                    </div>

                    @if ($needs === [])
                        <div class="mt-5 rounded-2xl border border-dashed border-ink/15 bg-paper/70 p-6 text-sm text-muted">Потреби зʼявляться після аналізу джерел.</div>
                    @else
                        <div class="mt-5 divide-y divide-ink/10 overflow-hidden rounded-2xl border border-ink/10 bg-paper">
                            @foreach ($needs as $need)
                                <div class="flex items-start justify-between gap-4 p-4">
                                    <div>
                                        <p class="font-bold">{{ $need['name'] ?? $need['item'] ?? 'Позиція' }}</p>
                                        @if ($need['notes'] ?? null)<p class="mt-1 text-xs leading-5 text-muted">{{ $need['notes'] }}</p>@endif
                                    </div>
                                    <span class="shrink-0 rounded-full bg-green-soft px-3 py-1 text-sm font-extrabold text-green-dark">{{ $need['quantity'] ?? $need['amount'] ?? '—' }}{{ isset($need['unit']) ? ' '.$need['unit'] : '' }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>
            </div>

            <section class="mt-10 rounded-[30px] border-2 border-ink bg-ink p-5 text-white shadow-[6px_7px_0_#F24B35] sm:p-7">
                <div class="flex items-center gap-3">
                    <span class="grid size-9 -rotate-2 place-items-center rounded-[45%] bg-orange font-display text-xl text-white">3</span>
                    <div>
                        <h2 class="font-display text-3xl tracking-tight">Кошик Сільпо</h2>
                        <p class="mt-0.5 text-sm text-white/55">Без ручного вибору товарів</p>
                    </div>
                </div>

                @if ($planItems === [])
                    <div class="mt-6 rounded-2xl border border-white/15 bg-white/5 p-6 text-sm text-white/60">Товарний план зʼявиться після підбору товарів.</div>
                @else
                    <div class="mt-6 divide-y divide-white/10 overflow-hidden rounded-2xl bg-white/7">
                        @foreach ($planItems as $item)
                            <div class="grid gap-2 p-4 sm:grid-cols-[1fr_auto_auto] sm:items-center sm:gap-6">
                                <div>
                                    <p class="font-bold">{{ $item['name'] ?? 'Товар' }}</p>
                                    @if ($item['matched_need'] ?? null)<p class="mt-1 text-xs text-white/50">Для: {{ $item['matched_need'] }}</p>@endif
                                </div>
                                <p class="text-sm text-white/70">{{ $item['quantity'] ?? 1 }} × {{ isset($item['price']) ? \Illuminate\Support\Number::currency($item['price'], in: $event->currency, locale: 'uk') : '—' }}</p>
                                <p class="font-bold">{{ isset($item['line_total']) ? \Illuminate\Support\Number::currency($item['line_total'], in: $event->currency, locale: 'uk') : '—' }}</p>
                            </div>
                        @endforeach
                    </div>

                    @if ($unresolvedItems !== [])
                        <div class="mt-4 rounded-2xl bg-yellow/15 p-4 text-sm text-yellow">
                            <p class="font-bold">Не вдалося підібрати: {{ implode(', ', array_map(fn ($item) => is_array($item) ? ($item['name'] ?? 'позиція') : $item, $unresolvedItems)) }}</p>
                        </div>
                    @endif

                    <div class="mt-6 flex flex-col gap-4 border-t border-white/15 pt-6 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-xs text-white/50">Орієнтовна сума</p>
                            <p class="mt-1 text-3xl font-bold">{{ \Illuminate\Support\Number::currency($event->estimated_total ?? $plan['total'] ?? 0, in: $event->currency, locale: 'uk') }}</p>
                        </div>

                        @if ($event->isCartCurrent() && $checkoutUrl)
                            <a class="rounded-2xl bg-orange px-6 py-4 text-center font-extrabold text-white transition hover:-translate-y-0.5 hover:bg-orange-dark" href="{{ $checkoutUrl }}" rel="noopener noreferrer" target="_blank">Перейти до кошика Сільпо ↗</a>
                        @elseif ($event->isPlanCurrent())
                            <form method="POST" action="{{ route('events.cart-sync', $event) }}">
                                @csrf
                                <button class="w-full rounded-2xl bg-orange px-6 py-4 font-extrabold text-white transition hover:-translate-y-0.5 hover:bg-orange-dark" type="submit">
                                    {{ $event->cart_synced_at ? 'Оновити кошик' : 'Додати все в кошик' }}
                                </button>
                            </form>
                        @else
                            <button class="cursor-not-allowed rounded-2xl bg-white/10 px-6 py-4 font-bold text-white/40" type="button" disabled>Спершу оновлюємо список</button>
                        @endif
                    </div>
                @endif
            </section>
        @elseif ($event->sources->isEmpty())
            <section class="mt-10 rounded-[28px] border-2 border-dashed border-green/45 bg-paper/60 px-6 py-12 text-center">
                <p class="font-display text-4xl text-green-dark" aria-hidden="true">↑</p>
                <h2 class="mt-3 font-display text-3xl">Почніть із переписки</h2>
                <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-muted">Додайте все одразу або частинами. Кожне нове повідомлення оновлюватиме один спільний стан події.</p>
            </section>
        @endif

        @if ($event->sources->isNotEmpty())
            <section class="mt-10 border-t-2 border-ink/10 pt-8">
                <details>
                    <summary class="cursor-pointer text-sm font-bold text-muted">Додані джерела ({{ $event->sources->count() }})</summary>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($event->sources as $source)
                            <article class="overflow-hidden rounded-2xl border border-ink/10 bg-paper">
                                @if ($source->type === \App\EventSourceType::Image)
                                    <img class="h-40 w-full object-cover" src="{{ route('events.sources.show', [$event, $source]) }}" alt="{{ $source->original_name ?: 'Скриншот переписки' }}" loading="lazy">
                                @else
                                    <p class="line-clamp-6 min-h-40 whitespace-pre-line p-4 text-sm leading-6">{{ $source->text }}</p>
                                @endif
                                <div class="flex items-center justify-between border-t border-ink/10 px-4 py-3 text-xs text-muted">
                                    <span>{{ $source->type === \App\EventSourceType::Image ? ($source->original_name ?: 'Скриншот') : 'Текст' }}</span>
                                    <span>{{ $source->created_at->diffForHumans() }}</span>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </details>
            </section>
        @endif
    </div>
</x-layouts.app>
