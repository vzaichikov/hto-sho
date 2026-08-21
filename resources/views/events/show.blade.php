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
        class="mx-auto max-w-6xl px-4 py-6 sm:px-6 sm:py-10"
        data-event-workspace
        data-event-status-url="{{ route('events.status', $event) }}"
        data-event-status="{{ $event->status->value }}"
        data-event-state-version="{{ $event->state_version }}"
        data-event-updated-at="{{ $event->updated_at->toISOString() }}"
    >
        <a class="inline-flex items-center gap-2 text-sm font-bold text-muted transition hover:text-ink" href="{{ route('events.index') }}">
            <span aria-hidden="true">←</span> Усі події
        </a>

        <x-flash-messages />

        <header class="mt-5 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="truncate text-3xl font-bold tracking-[-0.045em] sm:text-4xl">{{ $event->title }}</h1>
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
                <summary class="cursor-pointer list-none rounded-full border border-ink/15 bg-white px-4 py-2 text-sm font-bold hover:border-ink/40">Налаштування</summary>
                <div class="absolute right-0 z-20 mt-2 w-[min(22rem,calc(100vw-2rem))] rounded-2xl border border-ink/10 bg-white p-4 shadow-xl">
                    <form method="POST" action="{{ route('events.update', $event) }}">
                        @csrf
                        @method('PATCH')
                        <label class="text-xs font-bold text-muted" for="title">Назва події</label>
                        <input class="mt-2 w-full rounded-xl border border-ink/15 bg-canvas px-3 py-2.5 text-sm outline-none focus:border-ink" id="title" name="title" maxlength="120" required value="{{ $event->title }}">
                        <label class="mt-4 block text-xs font-bold text-muted" for="description">Короткий опис</label>
                        <textarea class="mt-2 min-h-20 w-full resize-y rounded-xl border border-ink/15 bg-canvas px-3 py-2.5 text-sm outline-none focus:border-ink" id="description" name="description" maxlength="1000" placeholder="Пікнік у парку, без складного готування">{{ $event->description }}</textarea>
                        <div class="mt-4 grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-bold text-muted" for="people_count">Кількість людей</label>
                                <input class="mt-2 w-full rounded-xl border border-ink/15 bg-canvas px-3 py-2.5 text-sm outline-none focus:border-ink" id="people_count" name="people_count" type="number" min="1" max="10000" value="{{ $event->people_count }}" placeholder="Ще невідомо">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-muted" for="budget_amount">Бюджет, ₴</label>
                                <input class="mt-2 w-full rounded-xl border border-ink/15 bg-canvas px-3 py-2.5 text-sm outline-none focus:border-ink" id="budget_amount" name="budget_amount" type="number" min="0" step="0.01" value="{{ $event->budget_amount }}" placeholder="Не задано">
                            </div>
                        </div>
                        <p class="mt-2 text-xs leading-5 text-muted">Кількість можна не вказувати — спробуємо визначити її з переписки.</p>
                        <button class="mt-3 w-full rounded-xl bg-ink px-4 py-2.5 text-sm font-bold text-white" type="submit">Зберегти контекст</button>
                    </form>

                    <form class="mt-4 border-t border-ink/10 pt-4" method="POST" action="{{ route('events.destroy', $event) }}" data-confirm="Видалити подію разом з усіма скриншотами?">
                        @csrf
                        @method('DELETE')
                        <button class="text-sm font-bold text-red-600 hover:text-red-800" type="submit">Видалити подію</button>
                    </form>
                </div>
            </details>
        </header>

        <section class="mt-8 overflow-hidden rounded-[28px] border-2 border-ink bg-white shadow-[7px_8px_0_#1d241f]" id="composer">
            <div class="border-b border-ink/10 bg-lime/25 px-5 py-4 sm:px-7">
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-muted">Додати нові дані</p>
                <h2 class="mt-1 text-xl font-bold">Вставте переписку або скриншоти</h2>
            </div>

            <form class="p-5 sm:p-7" method="POST" action="{{ route('events.sources.store', $event) }}" enctype="multipart/form-data" data-source-composer>
                @csrf
                <label class="sr-only" for="source-text">Текст переписки або уточнення</label>
                <textarea class="min-h-40 w-full resize-y rounded-2xl border border-ink/15 bg-canvas p-4 text-base leading-7 outline-none placeholder:text-muted/70 focus:border-ink focus:ring-4 focus:ring-lime/30" id="source-text" name="text" maxlength="50000" placeholder="Вставте повідомлення з чату або напишіть уточнення: Марина не прийде, а Саша бере напої…">{{ old('text') }}</textarea>

                <div class="mt-4 rounded-2xl border-2 border-dashed border-ink/20 bg-canvas p-5 text-center transition data-[dragging=true]:border-orange data-[dragging=true]:bg-orange/5" data-file-dropzone>
                    <input class="sr-only" id="source-images" name="images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple data-file-input>
                    <label class="cursor-pointer" for="source-images">
                        <span class="mx-auto grid size-11 place-items-center rounded-2xl bg-white text-xl shadow-sm" aria-hidden="true">↥</span>
                        <span class="mt-3 block text-sm font-bold">Перетягніть, вставте або виберіть скриншоти</span>
                        <span class="mt-1 block text-xs text-muted">JPG, PNG чи WebP · до 8 МБ · максимум 10 файлів</span>
                    </label>
                    <div class="mt-4 hidden grid-cols-2 gap-3 text-left sm:grid-cols-4" data-file-previews></div>
                </div>

                <button class="mt-5 inline-flex w-full items-center justify-center rounded-2xl bg-ink px-6 py-4 font-bold text-white shadow-[4px_4px_0_#ff7d3d] transition hover:-translate-y-0.5 sm:w-auto" type="submit">
                    Додати й оновити <span class="ml-3 text-lime" aria-hidden="true">→</span>
                </button>
            </form>
        </section>

        @if ($event->status === \App\EventStatus::Processing)
            <section class="mt-7 rounded-[24px] border border-orange/30 bg-orange/10 p-5" aria-live="polite">
                <div class="flex items-start gap-4">
                    <span class="mt-0.5 grid size-10 shrink-0 animate-pulse place-items-center rounded-2xl bg-orange font-bold text-white">…</span>
                    <div>
                        <h2 class="font-bold">Дані додано, готуємо оновлення</h2>
                        <p class="mt-1 text-sm leading-6 text-muted">Сторінка сама покаже новий результат після обробки. У цьому етапі MVP джерела вже збережені, а AI-обробник підключається окремим наступним кроком.</p>
                    </div>
                </div>
            </section>
        @elseif ($event->status === \App\EventStatus::Failed)
            <section class="mt-7 rounded-[24px] border border-red-200 bg-red-50 p-5 text-red-800">
                <h2 class="font-bold">Не вдалося оновити результат</h2>
                <p class="mt-1 text-sm">{{ $event->analysis_error ?: 'Додайте уточнення або повторіть спробу.' }}</p>
            </section>
        @endif

        @if ($state !== [])
            @if ($warnings !== [])
                <section class="mt-8 rounded-[24px] border-2 border-orange bg-white p-5 shadow-[5px_5px_0_#ff7d3d] sm:p-6">
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
                        <span class="grid size-9 place-items-center rounded-xl bg-lime font-bold">1</span>
                        <h2 class="text-2xl font-bold tracking-tight">Що відомо</h2>
                    </div>

                    @if ($state['summary'] ?? null)
                        <p class="mt-5 text-base leading-7 text-muted">{{ $state['summary'] }}</p>
                    @endif

                    @if ($participants !== [])
                        <div class="mt-5 grid gap-3 sm:grid-cols-2">
                            @foreach ($participants as $participant)
                                <article class="rounded-2xl border border-ink/10 bg-white p-4">
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
                            <div class="rounded-2xl bg-lime/25 p-4">
                                <p class="text-xs font-bold text-muted">БЮДЖЕТ</p>
                                <p class="mt-1 text-xl font-bold">{{ $event->budget_amount ? \Illuminate\Support\Number::currency($event->budget_amount, in: $event->currency, locale: 'uk') : (is_array($state['budget']) ? ($state['budget']['summary'] ?? json_encode($state['budget'], JSON_UNESCAPED_UNICODE)) : $state['budget']) }}</p>
                            </div>
                        @endif
                        @if ($agreements !== [])
                            <div class="rounded-2xl bg-orange/10 p-4">
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
                        <span class="grid size-9 place-items-center rounded-xl bg-orange font-bold text-white">2</span>
                        <h2 class="text-2xl font-bold tracking-tight">Що треба купити</h2>
                    </div>

                    @if ($needs === [])
                        <div class="mt-5 rounded-2xl border border-dashed border-ink/15 bg-white/60 p-6 text-sm text-muted">Потреби зʼявляться після аналізу джерел.</div>
                    @else
                        <div class="mt-5 divide-y divide-ink/10 overflow-hidden rounded-2xl border border-ink/10 bg-white">
                            @foreach ($needs as $need)
                                <div class="flex items-start justify-between gap-4 p-4">
                                    <div>
                                        <p class="font-bold">{{ $need['name'] ?? $need['item'] ?? 'Позиція' }}</p>
                                        @if ($need['notes'] ?? null)<p class="mt-1 text-xs leading-5 text-muted">{{ $need['notes'] }}</p>@endif
                                    </div>
                                    <span class="shrink-0 rounded-full bg-canvas px-3 py-1 text-sm font-bold">{{ $need['quantity'] ?? $need['amount'] ?? '—' }}{{ isset($need['unit']) ? ' '.$need['unit'] : '' }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>
            </div>

            <section class="mt-10 rounded-[28px] bg-ink p-5 text-white sm:p-7">
                <div class="flex items-center gap-3">
                    <span class="grid size-9 place-items-center rounded-xl bg-lime font-bold text-ink">3</span>
                    <div>
                        <h2 class="text-2xl font-bold tracking-tight">Кошик Сільпо</h2>
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
                        <div class="mt-4 rounded-2xl bg-orange/15 p-4 text-sm text-orange-100">
                            <p class="font-bold">Не вдалося підібрати: {{ implode(', ', array_map(fn ($item) => is_array($item) ? ($item['name'] ?? 'позиція') : $item, $unresolvedItems)) }}</p>
                        </div>
                    @endif

                    <div class="mt-6 flex flex-col gap-4 border-t border-white/15 pt-6 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-xs text-white/50">Орієнтовна сума</p>
                            <p class="mt-1 text-3xl font-bold">{{ \Illuminate\Support\Number::currency($event->estimated_total ?? $plan['total'] ?? 0, in: $event->currency, locale: 'uk') }}</p>
                        </div>

                        @if ($event->isCartCurrent() && $checkoutUrl)
                            <a class="rounded-2xl bg-lime px-6 py-4 text-center font-bold text-ink" href="{{ $checkoutUrl }}" rel="noopener noreferrer" target="_blank">Перейти до кошика Сільпо ↗</a>
                        @elseif ($event->isPlanCurrent())
                            <form method="POST" action="{{ route('events.cart-sync', $event) }}">
                                @csrf
                                <button class="w-full rounded-2xl bg-lime px-6 py-4 font-bold text-ink transition hover:-translate-y-0.5" type="submit">
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
            <section class="mt-10 rounded-[28px] border-2 border-dashed border-ink/15 px-6 py-12 text-center">
                <p class="text-3xl" aria-hidden="true">↑</p>
                <h2 class="mt-3 text-xl font-bold">Почніть із переписки</h2>
                <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-muted">Додайте все одразу або частинами. Кожне нове повідомлення оновлюватиме один спільний стан події.</p>
            </section>
        @endif

        @if ($event->sources->isNotEmpty())
            <section class="mt-10 border-t border-ink/10 pt-8">
                <details>
                    <summary class="cursor-pointer text-sm font-bold text-muted">Додані джерела ({{ $event->sources->count() }})</summary>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($event->sources as $source)
                            <article class="overflow-hidden rounded-2xl border border-ink/10 bg-white">
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
