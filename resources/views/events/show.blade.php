@php
    $state = $event->state ?? [];
    $participants = $state['participants'] ?? [];
    $restrictions = $state['restrictions'] ?? [];
    $agreements = $state['agreements'] ?? [];
    $warnings = $state['warnings'] ?? [];
    $plan = $event->shopping_plan ?? [];
    $planItems = collect($plan['items'] ?? []);
    $planIsCurrent = $event->isPlanCurrent();
    $hasBlockingQuestion = $needsQuestionRefresh || collect($questions)->contains('blocking', true);
    $analysisActive = $event->hasActiveAnalysis();
    $analysisProgress = $event->analysis_stage?->progress() ?? 0;
    $analysisMessage = $event->analysis_stage?->message(
        $event->analysis_task_id,
        $event->analysis_started_at,
    ) ?? '';
    $participantStatusLabels = [
        'confirmed' => 'Буде',
        'declined' => 'Не буде',
        'uncertain' => 'Ще думає',
        'unknown' => 'Поки невідомо',
    ];
    $categoryLabels = [
        'food' => 'Їжа',
        'water' => 'Вода',
        'soft_drinks' => 'Безалкогольні напої',
        'alcohol' => 'Алкоголь',
        'supplies' => 'Речі для події',
        'other' => 'Інше',
    ];
    $tabs = [
        'context' => 'Контекст',
        'questions' => 'Питання',
        'plan' => 'Список',
        'silpo' => 'Сільпо',
    ];
    $sourceById = $event->sources->keyBy('id');
    $correctionPanelOpen = $errors->has('correction') || $errors->has('plan_state_version');
    $latestCartRun = $event->latestCartRun;
    $stagedCartProducts = collect($latestCartRun?->staged_items ?? []);
    $stagedCartProductIds = $stagedCartProducts->pluck('product_id')->filter()->unique();
    $hasCompletedCart = $latestCartRun && in_array($latestCartRun->status, [
        \App\CartRunStatus::Synced,
        \App\CartRunStatus::Partial,
    ], true);
    $completedCartProducts = $hasCompletedCart
        ? collect(data_get($latestCartRun->state, 'verified_cart.items', []))
            ->filter(fn (mixed $product): bool => is_array($product)
                && $stagedCartProductIds->containsStrict(data_get($product, 'product_id')))
            ->values()
        : collect();

    if ($hasCompletedCart && $completedCartProducts->isEmpty()) {
        $completedCartProducts = $stagedCartProducts
            ->groupBy('product_id')
            ->map(function ($products): array {
                $product = $products->first();
                $product['quantity'] = $products->sum(fn (array $item): float => (float) data_get($item, 'quantity', 0));
                $product['total'] = $products->sum(fn (array $item): float => (float) data_get($item, 'estimated_total', 0));

                return $product;
            })
            ->values();
    }
@endphp

<x-layouts.app :title="$event->title">
    <div
        class="mx-auto max-w-6xl px-4 py-6 sm:px-6 sm:py-10"
        data-event-workspace
        data-event-status-url="{{ route('events.status', $event) }}"
        data-event-state-version="{{ $event->state_version }}"
        data-event-plan-status="{{ $event->plan_generation_status->value }}"
    >
        <a class="inline-flex -rotate-1 items-center gap-2 rounded-sm bg-yellow/60 px-3 py-1.5 font-display text-lg transition hover:rotate-0 hover:bg-yellow focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-green" href="{{ route('events.index') }}">
            <span aria-hidden="true">←</span> До всіх подій
        </a>

        <x-flash-messages class="mt-4" />

        <header class="mt-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="max-w-full break-words font-display text-4xl leading-[1.1] tracking-[-0.035em] sm:text-5xl">{{ $event->title }}</h1>
                    <x-status-badge :status="$event->status" data-event-status-badge />
                </div>
                <p class="mt-2 text-sm text-muted">Матеріалів: {{ $event->sources->count() }}</p>
            </div>

            <div class="flex shrink-0 items-start gap-2">
                <a class="rounded-full border-2 border-ink bg-paper px-4 py-2 text-sm font-extrabold shadow-[2px_2px_0_#F7C84B] transition hover:-translate-y-0.5 hover:bg-yellow/30 focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-green" href="{{ route('events.journal.index', $event) }}">Журнал</a>

            <details class="relative">
                <summary class="cursor-pointer list-none rounded-full border-2 border-ink bg-paper px-4 py-2 text-sm font-extrabold shadow-[2px_2px_0_#F7C84B] transition hover:-translate-y-0.5 hover:bg-yellow/30 focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-green">Налаштування</summary>
                <div class="absolute right-0 z-30 mt-3 w-[min(22rem,calc(100vw-2rem))] rounded-2xl border-2 border-ink bg-paper p-4 shadow-[5px_6px_0_#20201D]">
                    <form method="POST" action="{{ route('events.update', $event) }}">
                        @csrf
                        @method('PATCH')
                        <label class="text-xs font-bold text-muted" for="title">Назва події</label>
                        <input class="mt-2 w-full rounded-xl border border-ink/20 bg-canvas px-3 py-2.5 text-sm outline-none focus:border-green focus:ring-3 focus:ring-green/15" id="title" name="title" maxlength="120" required value="{{ $event->title }}">
                        <label class="mt-4 block text-xs font-bold text-muted" for="description">Короткий опис</label>
                        <textarea class="mt-2 min-h-20 w-full resize-y rounded-xl border border-ink/20 bg-canvas px-3 py-2.5 text-sm outline-none focus:border-green focus:ring-3 focus:ring-green/15" id="description" name="description" maxlength="1000">{{ $event->description }}</textarea>
                        <div class="mt-4 grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-bold text-muted" for="people_count">Людей</label>
                                <input class="mt-2 w-full rounded-xl border border-ink/20 bg-canvas px-3 py-2.5 text-sm" id="people_count" name="people_count" type="number" min="1" max="10000" value="{{ $event->people_count }}">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-muted" for="budget_amount">Бюджет, ₴</label>
                                <input class="mt-2 w-full rounded-xl border border-ink/20 bg-canvas px-3 py-2.5 text-sm" id="budget_amount" name="budget_amount" type="number" min="0" step="0.01" value="{{ $event->budget_amount }}">
                            </div>
                        </div>
                        <button class="mt-4 w-full rounded-xl bg-orange px-4 py-2.5 text-sm font-extrabold text-white hover:bg-orange-dark focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-green" type="submit">Зберегти й оновити</button>
                    </form>

                    <form class="mt-4 border-t border-ink/10 pt-4" method="POST" action="{{ route('events.destroy', $event) }}" data-confirm="Видалити подію разом з усіма її матеріалами?">
                        @csrf
                        @method('DELETE')
                        <button class="text-sm font-bold text-orange-dark hover:text-beet focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-green" type="submit">Видалити подію</button>
                    </form>
                </div>
            </details>
            </div>
        </header>

        <nav class="mt-7 grid grid-cols-2 gap-2 sm:grid-cols-4" aria-label="Кроки підготовки події">
            @foreach ($tabs as $tab => $label)
                <a
                    class="group flex min-w-0 items-center gap-2 rounded-2xl border-2 px-3 py-3 text-sm font-extrabold transition focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-green sm:px-4 {{ $activeTab === $tab ? 'border-ink bg-yellow text-ink shadow-[3px_3px_0_#20201D]' : 'border-ink/15 bg-paper text-muted hover:border-green hover:text-ink' }}"
                    href="{{ route('events.show', ['event' => $event, 'tab' => $tab]) }}"
                    @if ($activeTab === $tab) aria-current="step" @endif
                >
                    <span class="grid size-7 shrink-0 place-items-center rounded-full {{ $activeTab === $tab ? 'bg-ink text-paper' : 'bg-canvas text-green-dark group-hover:bg-green-soft' }}">{{ $loop->iteration }}</span>
                    <span class="truncate">{{ $label }}</span>
                    @if ($tab === 'questions' && count($questions) > 0)
                        <span class="ml-auto grid min-w-6 shrink-0 place-items-center rounded-full bg-orange px-1.5 py-0.5 text-xs font-black text-white" aria-label="Невирішених питань: {{ count($questions) }}">{{ count($questions) }}</span>
                    @endif
                </a>
            @endforeach
        </nav>

        @if ($activeTab === 'context')
            <section class="mt-7 overflow-hidden rounded-[30px] border-2 border-ink bg-paper shadow-[7px_8px_0_#20201D]" id="composer">
                <div class="relative min-h-28 overflow-hidden border-b-2 border-ink/10 bg-yellow/35 px-5 py-5 pr-28 sm:px-7 sm:pr-40">
                    <p class="font-display text-lg leading-[1.15] text-green-dark">Гусь слухає уважно</p>
                    <h2 class="mt-1 font-display text-3xl leading-[1.1]">Підкиньте новий контекст</h2>
                    <p class="mt-2 max-w-xl text-xs leading-5 text-muted sm:text-sm">Текст, уривки переписки чи картинки — усе, що допоможе скласти актуальний план.</p>
                    <img class="absolute -bottom-24 right-2 w-28 drop-shadow-lg sm:-bottom-32 sm:right-6 sm:w-40" src="{{ asset('images/brand/goose-sho.png') }}" alt="" aria-hidden="true">
                </div>

                <form class="p-5 sm:p-7" method="POST" action="{{ route('events.sources.store', $event) }}" enctype="multipart/form-data" data-source-composer>
                    @csrf
                    <label class="sr-only" for="source-text">Текст переписки або уточнення</label>
                    <textarea class="min-h-36 w-full resize-y rounded-2xl border border-ink/20 bg-canvas p-4 text-base leading-7 outline-none placeholder:text-muted/70 focus:border-green focus:ring-4 focus:ring-green/15" id="source-text" name="text" maxlength="50000" placeholder="Наприклад: Марина не прийде, Саша бере вугілля, а в Олі алергія на арахіс…">{{ old('text') }}</textarea>

                    <div class="mt-4 rounded-2xl border-2 border-dashed border-green/45 bg-green-soft/20 p-5 text-center transition data-[dragging=true]:border-orange data-[dragging=true]:bg-orange/5" data-file-dropzone>
                        <input class="sr-only" id="source-images" name="images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple data-file-input>
                        <input class="hidden" id="source-camera" type="file" accept="image/*" capture="environment" data-camera-input>
                        <label class="cursor-pointer" for="source-images">
                            <span class="mx-auto grid size-11 -rotate-3 place-items-center rounded-[45%] bg-yellow font-display text-2xl transition hover:rotate-0" aria-hidden="true">↥</span>
                            <span class="mt-3 block text-sm font-extrabold">Перетягніть, вставте або виберіть картинки</span>
                            <span class="mt-1 block text-xs text-muted">JPG, PNG чи WebP · до 8 МБ · максимум 10 файлів</span>
                        </label>
                        <button class="mt-4 inline-flex items-center gap-2 rounded-full border-2 border-ink bg-paper px-4 py-2 text-xs font-extrabold shadow-[2px_2px_0_#F7C84B] transition hover:-translate-y-0.5 hover:bg-yellow/35 focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-green" type="button" data-camera-trigger>
                            <span aria-hidden="true">📷</span> Зробити фото
                        </button>
                        <div class="mt-4 hidden grid-cols-2 gap-3 text-left sm:grid-cols-4" data-file-previews></div>
                    </div>

                    <button class="mt-5 inline-flex w-full items-center justify-center rounded-2xl bg-orange px-6 py-4 font-extrabold text-white shadow-[4px_4px_0_#F7C84B] transition hover:-translate-y-0.5 hover:bg-orange-dark focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-green sm:w-auto" type="submit">
                        Додати й оновити <span class="ml-3 text-yellow" aria-hidden="true">→</span>
                    </button>
                </form>
            </section>

            @if ($event->sources->isNotEmpty())
                <details class="mt-6 rounded-[24px] border-2 border-ink/15 bg-paper p-4 sm:p-5">
                    <summary class="cursor-pointer font-display text-2xl focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-green">Що Гусь уже бачив <span class="text-base text-muted">({{ $event->sources->count() }})</span></summary>
                    <div class="mt-4 space-y-3">
                        @foreach ($event->sources->sortByDesc('created_at') as $source)
                            @php
                                $extraction = $source->imageExtraction;
                                $materialLabel = match ($source->origin) {
                                    'question_answer' => 'Відповідь організатора',
                                    'plan_correction' => 'Коректива до списку',
                                    default => $source->type === \App\EventSourceType::Image ? 'Картинка' : 'Нотатка',
                                };
                            @endphp
                            <article class="rounded-2xl bg-canvas p-4" data-source-card data-source-id="{{ $source->id }}" data-source-status="{{ $source->status->value }}">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-xs font-extrabold uppercase tracking-[0.1em] text-green-dark">{{ $materialLabel }}</span>
                                        <span class="rounded-full bg-paper px-2.5 py-1 text-xs font-bold" data-source-status-label>{{ $source->status->label() }}</span>
                                        @if ($source->inclusion === \App\EventSourceInclusion::Dismissed)
                                            <span class="rounded-full bg-ink/8 px-2.5 py-1 text-xs font-bold text-muted">Гусь відклав убік</span>
                                        @endif
                                    </div>
                                    <time class="text-xs text-muted" datetime="{{ $source->created_at->toISOString() }}">{{ $source->created_at->format('d.m.Y H:i') }}</time>
                                </div>

                                <div class="mt-3 {{ $source->type === \App\EventSourceType::Image ? 'grid gap-4 sm:grid-cols-[8rem_1fr]' : '' }}">
                                    @if ($source->type === \App\EventSourceType::Image)
                                        <a class="block overflow-hidden rounded-xl bg-paper focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-green" href="{{ route('events.sources.show', [$event, $source]) }}" target="_blank">
                                            <img class="h-28 w-full object-cover" src="{{ route('events.sources.show', [$event, $source]) }}" alt="{{ $source->original_name ?: 'Додана картинка' }}" loading="lazy">
                                        </a>
                                    @endif

                                    <div class="min-w-0">
                                        @if ($source->type === \App\EventSourceType::Text)
                                            <p class="whitespace-pre-wrap text-sm leading-6">{{ $source->text }}</p>
                                        @elseif (in_array($source->status, [\App\EventSourceStatus::Pending, \App\EventSourceStatus::Processing], true))
                                            <p class="text-sm font-bold" data-source-message>Гусь уважно роздивляється картинку.</p>
                                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-ink/10">
                                                <div class="h-full rounded-full bg-orange transition-[width] duration-500" style="width: {{ $source->status === \App\EventSourceStatus::Pending ? 12 : 62 }}%" data-source-progress></div>
                                            </div>
                                        @elseif ($source->status === \App\EventSourceStatus::Processed)
                                            @if ($extraction?->source_summary)
                                                <p class="text-sm leading-6">{{ $extraction->source_summary }}</p>
                                            @endif
                                            @if ($extraction?->ocr_text)
                                                <details class="mt-3 rounded-xl bg-paper p-3">
                                                    <summary class="cursor-pointer text-sm font-extrabold">Що Гусь прочитав</summary>
                                                    <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-muted">{{ $extraction->ocr_text }}</p>
                                                </details>
                                            @endif
                                            @if ($extraction?->classification === \App\ImageClassification::Irrelevant)
                                                <div class="mt-3 rounded-xl border border-ink/10 bg-paper p-3">
                                                    <p class="text-sm font-bold">Гусь відклав це вбік</p>
                                                    <p class="mt-1 text-sm leading-6 text-muted">{{ $extraction->dismissal_reason }}</p>
                                                    <form class="mt-2" method="POST" action="{{ route('events.sources.inclusion', [$event, $source]) }}">
                                                        @csrf
                                                        @method('PATCH')
                                                        <input type="hidden" name="inclusion" value="{{ $source->inclusion === \App\EventSourceInclusion::Forced ? \App\EventSourceInclusion::Dismissed->value : \App\EventSourceInclusion::Forced->value }}">
                                                        <button class="text-sm font-extrabold text-orange-dark underline decoration-2 underline-offset-4" type="submit">
                                                            {{ $source->inclusion === \App\EventSourceInclusion::Forced ? 'Гусь мав рацію — відкласти' : 'Гусь, це все ж важливо' }}
                                                        </button>
                                                    </form>
                                                </div>
                                            @endif
                                        @else
                                            <div class="rounded-xl border border-orange/35 bg-orange/8 p-3">
                                                <p class="text-sm font-extrabold text-orange-dark">Картинка не піддалася Гусю.</p>
                                                <p class="mt-1 text-sm text-muted">{{ $source->processing_error }}</p>
                                                <form class="mt-2" method="POST" action="{{ route('events.sources.retry', [$event, $source]) }}">
                                                    @csrf
                                                    <button class="text-sm font-extrabold underline decoration-2 underline-offset-4" type="submit">Гусь, ще раз</button>
                                                </form>
                                            </div>
                                        @endif

                                        <form class="mt-3 flex justify-end border-t border-ink/8 pt-2" method="POST" action="{{ route('events.sources.destroy', [$event, $source]) }}" data-confirm="Прибрати цей матеріал із події?">
                                            @csrf
                                            @method('DELETE')
                                            <button class="text-xs font-extrabold text-muted hover:text-orange-dark" type="submit">Прибрати матеріал</button>
                                        </form>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </details>
            @endif

            <section class="mt-7 rounded-[30px] border-2 border-ink bg-paper p-5 shadow-[6px_7px_0_#F7C84B] sm:p-7" data-context-summary>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-green-dark">Актуальна картина</p>
                        <h2 class="mt-1 font-display text-3xl">Що Гусь зрозумів</h2>
                    </div>
                    @if ($event->hasUnanalyzedChanges())
                        <span class="rounded-full bg-yellow px-3 py-1 text-xs font-extrabold">Гусь почув нове й уже перераховує</span>
                    @endif
                </div>

                @if ($state === [])
                    <div class="mt-5 rounded-2xl border border-dashed border-green/45 bg-green-soft/20 p-5">
                        <p class="font-bold">{{ $analysisActive ? 'Гусь уже збирає першу картину докупи.' : 'Поки Гусю бракує контексту.' }}</p>
                        <p class="mt-1 text-sm text-muted">Додайте кілька слів або картинку вище — далі все підхопиться саме.</p>
                    </div>
                @else
                    <p class="mt-5 text-base leading-7 text-muted">{{ $state['summary'] ?? 'Головне вже зібрано, але короткий опис десь вислизнув.' }}</p>

                    @if ($warnings !== [])
                        <div class="mt-5 rounded-2xl border border-orange/30 bg-orange/8 p-4">
                            <p class="text-xs font-extrabold uppercase tracking-[0.12em] text-orange-dark">На що звернути увагу</p>
                            <ul class="mt-2 space-y-2 text-sm leading-6">
                                @foreach ($warnings as $warning)
                                    <li>→ {{ is_array($warning) ? ($warning['message'] ?? '') : $warning }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="mt-6 grid gap-5 lg:grid-cols-2">
                        <div>
                            <h3 class="font-display text-2xl">Люди</h3>
                            <div class="mt-3 space-y-3">
                                @forelse ($participants as $participant)
                                    <article class="rounded-2xl bg-canvas p-4">
                                        <div class="flex items-center justify-between gap-3">
                                            <p class="font-extrabold">{{ $participant['name'] ?? 'Невідомий учасник' }}</p>
                                            <span class="rounded-full bg-paper px-2.5 py-1 text-xs font-bold text-muted">{{ $participantStatusLabels[$participant['status'] ?? 'unknown'] ?? 'Поки невідомо' }}</span>
                                        </div>
                                        @foreach (['preferences' => 'Хоче', 'restrictions' => 'Не можна', 'allergies' => 'Алергії', 'brings' => 'Бере'] as $key => $label)
                                            @if (($participant[$key] ?? []) !== [])
                                                <p class="mt-2 text-xs leading-5"><b>{{ $label }}:</b> {{ implode(', ', $participant[$key]) }}</p>
                                            @endif
                                        @endforeach
                                        @if (($participant['source_ids'] ?? []) !== [])
                                            <details class="mt-3 text-xs text-muted">
                                                <summary class="cursor-pointer font-bold">Звідки Гусь це взяв</summary>
                                                <ul class="mt-2 space-y-1">
                                                    @foreach ($participant['source_ids'] as $sourceId)
                                                        @if ($sourceById->has($sourceId))
                                                            @php $factSource = $sourceById->get($sourceId); @endphp
                                                            <li>{{ $factSource->origin === 'question_answer' ? 'Відповідь організатора' : ($factSource->type === \App\EventSourceType::Image ? 'Картинка' : 'Нотатка') }} · {{ $factSource->created_at->format('d.m.Y H:i') }}</li>
                                                        @endif
                                                    @endforeach
                                                </ul>
                                            </details>
                                        @endif
                                    </article>
                                @empty
                                    <p class="rounded-2xl border border-dashed border-ink/15 p-4 text-sm text-muted">Імен поки не назбиралось.</p>
                                @endforelse
                            </div>
                        </div>

                        <div class="space-y-5">
                            @foreach ([['label' => 'Обмеження й алергії', 'items' => $restrictions, 'key' => 'restriction'], ['label' => 'Домовленості', 'items' => $agreements, 'key' => 'summary']] as $section)
                                @if ($section['items'] !== [])
                                    <div>
                                        <h3 class="font-display text-2xl">{{ $section['label'] }}</h3>
                                        <ul class="mt-2 space-y-2 text-sm leading-6">
                                            @foreach ($section['items'] as $item)
                                                <li class="rounded-xl bg-canvas px-4 py-3">
                                                    {{ is_array($item) ? ($item[$section['key']] ?? '') : $item }}
                                                    @if (is_array($item) && ($item['source_ids'] ?? []) !== [])
                                                        <details class="mt-2 text-xs text-muted">
                                                            <summary class="cursor-pointer font-bold">Звідки Гусь це взяв</summary>
                                                            <ul class="mt-2 space-y-1">
                                                                @foreach ($item['source_ids'] as $sourceId)
                                                                    @if ($sourceById->has($sourceId))
                                                                        @php $factSource = $sourceById->get($sourceId); @endphp
                                                                        <li>{{ $factSource->origin === 'question_answer' ? 'Відповідь організатора' : ($factSource->type === \App\EventSourceType::Image ? 'Картинка' : 'Нотатка') }} · {{ $factSource->created_at->format('d.m.Y H:i') }}</li>
                                                                    @endif
                                                                @endforeach
                                                            </ul>
                                                        </details>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($event->analysis_stage === \App\EventAnalysisStage::Failed)
                    <div class="mt-5 rounded-2xl border border-orange/40 bg-orange/10 p-4 text-orange-dark">
                        <p class="font-bold">Гусь перечепився й не зміг оновити картину.</p>
                        <p class="mt-1 text-sm">{{ $event->analysis_error }}</p>
                        <form class="mt-3" method="POST" action="{{ route('events.analysis.store', $event) }}" data-analysis-form>
                            @csrf
                            <button class="rounded-xl bg-orange px-4 py-2.5 text-sm font-extrabold text-white" type="submit" data-analysis-button>Гусь, спробуй ще раз</button>
                        </form>
                    </div>
                @endif
            </section>

            @if ($event->contextVersions->isNotEmpty())
                <section class="mt-7" aria-labelledby="context-history-title">
                    <h2 class="font-display text-3xl" id="context-history-title">Як Гусь розумів це раніше</h2>
                    <div class="mt-3 space-y-3">
                        @foreach ($event->contextVersions as $contextVersion)
                            <details class="rounded-2xl border border-ink/15 bg-paper p-4">
                                <summary class="cursor-pointer text-sm font-extrabold">Картина на {{ $contextVersion->created_at->format('d.m.Y H:i') }}</summary>
                                <p class="mt-3 text-sm leading-6 text-muted">{{ $contextVersion->state['summary'] ?? 'Короткий опис не зберігся.' }}</p>
                            </details>
                        @endforeach
                    </div>
                </section>
            @endif
        @elseif ($activeTab === 'questions')
            <section class="mt-7 rounded-[30px] border-2 border-ink bg-paper p-5 shadow-[6px_7px_0_#F7C84B] sm:p-7">
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-green-dark">Уточнення від Гуся</p>
                <h2 class="mt-1 font-display text-4xl">Питання до людей</h2>

                @if ($state === [])
                    <div class="mt-5 rounded-2xl border border-dashed border-green/45 bg-green-soft/20 p-5">
                        <p class="font-bold">Спершу Гусю треба зрозуміти саму подію.</p>
                        <a class="mt-3 inline-flex font-extrabold text-orange-dark underline decoration-2 underline-offset-4" href="{{ route('events.show', ['event' => $event, 'tab' => 'context']) }}">Перейти до контексту</a>
                    </div>
                @elseif ($needsQuestionRefresh)
                    <div class="mt-5 rounded-2xl border border-orange/35 bg-orange/10 p-5">
                        <p class="font-display text-2xl text-orange-dark">Гусь хоче освіжити питання</p>
                        <p class="mt-1 text-sm text-muted">Суть події збережена. Треба лише ще раз скласти зручні варіанти відповідей.</p>
                        <form class="mt-3" method="POST" action="{{ route('events.analysis.store', $event) }}" data-analysis-form>
                            @csrf
                            <button class="rounded-xl bg-orange px-4 py-2.5 text-sm font-extrabold text-white" type="submit" data-analysis-button>Гусь, освіжи питання</button>
                        </form>
                    </div>
                @elseif ($questions === [])
                    <div class="mt-5 rounded-2xl bg-green-soft/45 p-5">
                        <p class="font-display text-2xl text-green-dark">Усе важливе вже зʼясовано</p>
                        <p class="mt-1 text-sm text-muted">Гусь не має питань. Підозріло добре, але ходімо до списку.</p>
                        <a class="mt-3 inline-flex font-extrabold text-orange-dark underline decoration-2 underline-offset-4" href="{{ route('events.show', ['event' => $event, 'tab' => 'plan']) }}">Подивитися список</a>
                    </div>
                @else
                    @if ($event->hasUnanalyzedChanges())
                        <p class="mt-4 rounded-2xl bg-yellow/45 p-4 text-sm font-bold">Гусь почув нове й уже перераховує. Питання нижче ще корисні, але скоро можуть змінитися.</p>
                    @endif

                    <form class="mt-6 space-y-5" method="POST" action="{{ route('events.answers.store', $event) }}" data-questions-form>
                        @csrf
                        <input type="hidden" name="state_version" value="{{ $event->state_version }}">

                        @foreach ($questions as $question)
                            <fieldset class="rounded-[24px] border-2 {{ ($question['blocking'] ?? false) ? 'border-orange/50' : 'border-ink/15' }} bg-canvas p-4 sm:p-5">
                                <input type="hidden" name="answers[{{ $loop->index }}][question_key]" value="{{ $question['key'] }}">
                                <legend class="px-1 font-display text-2xl leading-tight">{{ $question['question'] }}</legend>
                                <p class="mt-2 text-sm leading-6 text-muted">{{ $question['impact'] }}</p>

                                <div class="mt-4 space-y-3">
                                    @foreach ($question['options'] as $option)
                                        <label class="flex cursor-pointer items-start gap-3 rounded-2xl border border-ink/15 bg-paper p-4 transition hover:border-green has-[:checked]:border-green has-[:checked]:ring-3 has-[:checked]:ring-green/15">
                                            <input class="mt-1 size-4 shrink-0 accent-green" type="radio" name="answers[{{ $loop->parent->index }}][selection]" value="{{ $option['label'] }}">
                                            <span>
                                                <span class="flex flex-wrap items-center gap-2 font-extrabold">
                                                    {{ $option['label'] }}
                                                    @if ($option['recommended'])
                                                        <span class="rounded-full bg-yellow px-2.5 py-1 text-[11px] uppercase tracking-wide">Гусь радить</span>
                                                    @endif
                                                </span>
                                                @if ($option['description'] !== '')
                                                    <span class="mt-1 block text-sm leading-5 text-muted">{{ $option['description'] }}</span>
                                                @endif
                                            </span>
                                        </label>
                                    @endforeach

                                    <label class="block rounded-2xl border border-ink/15 bg-paper p-4 focus-within:border-green focus-within:ring-3 focus-within:ring-green/15">
                                        <span class="flex items-center gap-3 font-extrabold">
                                            <input class="size-4 accent-green" type="radio" name="answers[{{ $loop->index }}][selection]" value="__custom__" data-question-custom-choice>
                                            Своя відповідь
                                        </span>
                                        <input class="mt-3 w-full rounded-xl border border-ink/20 bg-canvas px-3 py-2.5 text-sm outline-none focus:border-green" name="answers[{{ $loop->index }}][custom]" maxlength="2000" placeholder="Напишіть як є" data-question-custom-input>
                                    </label>
                                </div>
                            </fieldset>
                        @endforeach

                        <p class="text-sm text-muted">Можна відповісти лише на ті питання, які вже вдалося зʼясувати.</p>
                        <button class="w-full rounded-2xl bg-orange px-6 py-4 font-extrabold text-white shadow-[4px_4px_0_#F7C84B] transition hover:-translate-y-0.5 hover:bg-orange-dark focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-green sm:w-auto" type="submit">Передати відповіді Гусю</button>
                    </form>
                @endif
            </section>
        @elseif ($activeTab === 'plan')
            <section class="mt-7 rounded-[30px] border-2 border-ink bg-paper p-5 shadow-[6px_7px_0_#F7C84B] sm:p-7">
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-green-dark">Що знадобиться</p>
                <h2 class="mt-1 font-display text-4xl">Загальний список</h2>

                @if ($plan === [])
                    <div class="mt-5 rounded-2xl border border-dashed border-green/45 bg-green-soft/20 p-5">
                        @if ($state === [])
                            <p class="font-bold">Без контексту навіть Гусь не вгадає, що тут їдять.</p>
                            <a class="mt-3 inline-flex font-extrabold text-orange-dark underline decoration-2 underline-offset-4" href="{{ route('events.show', ['event' => $event, 'tab' => 'context']) }}">Додати контекст</a>
                        @elseif ($event->plan_generation_status === \App\PlanGenerationStatus::Failed)
                            <p class="font-bold">Гусь перечепився й не склав список.</p>
                            <p class="mt-1 text-sm text-muted">Попередній підсумок цілий. Попросіть Гуся спробувати ще раз.</p>
                            <form class="mt-3" method="POST" action="{{ route('events.analysis.store', $event) }}" data-analysis-form>
                                @csrf
                                <button class="rounded-xl bg-orange px-4 py-2.5 text-sm font-extrabold text-white" type="submit" data-analysis-button>Гусь, спробуй ще раз</button>
                            </form>
                        @else
                            <p class="font-bold">Гусь уже рахує, скільки всього треба.</p>
                            <p class="mt-1 text-sm text-muted">Вода, напої, їжа й дрібниці для події теж у полі зору.</p>
                        @endif
                    </div>
                @else
                    <div class="mt-5 grid gap-3 sm:grid-cols-2">
                        <button
                            class="w-full rounded-2xl border-2 border-ink bg-yellow/55 px-5 py-4 font-extrabold text-ink shadow-[3px_3px_0_#20201D] transition enabled:hover:-translate-y-0.5 enabled:hover:bg-yellow disabled:cursor-not-allowed disabled:opacity-45 focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-green"
                            type="button"
                            data-plan-correction-toggle
                            aria-controls="plan-correction-panel"
                            aria-expanded="{{ $correctionPanelOpen ? 'true' : 'false' }}"
                            @disabled(! $planIsCurrent)
                        >Внести корективу</button>
                        <button
                            class="w-full rounded-2xl bg-green px-5 py-4 font-extrabold text-white shadow-[4px_4px_0_#20201D] transition enabled:hover:-translate-y-0.5 enabled:hover:bg-green-dark disabled:cursor-not-allowed disabled:opacity-45 focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-green"
                            type="button"
                            data-silpo-dialog-open
                            @disabled(! $planIsCurrent || $hasBlockingQuestion)
                        >Відправити Гуся в Сільпо?</button>
                    </div>

                    <div class="mt-4 rounded-[22px] border-2 border-ink/15 bg-canvas p-4 sm:p-5 {{ $correctionPanelOpen ? '' : 'hidden' }}" id="plan-correction-panel" data-plan-correction-panel>
                        <form method="POST" action="{{ route('events.plan-corrections.store', $event) }}">
                            @csrf
                            <input type="hidden" name="plan_state_version" value="{{ $event->plan_state_version }}">
                            <label class="font-display text-2xl" for="plan-correction">Що Гусю змінити?</label>
                            <p class="mt-1 text-sm leading-6 text-muted">Напишіть як людині: «води вдвічі менше», «прибрати одноразовий посуд» або «додати фрукти».</p>
                            <textarea
                                class="mt-3 min-h-28 w-full resize-y rounded-2xl border border-ink/20 bg-paper p-4 text-base leading-7 outline-none placeholder:text-muted/70 focus:border-green focus:ring-4 focus:ring-green/15"
                                id="plan-correction"
                                name="correction"
                                maxlength="2000"
                                placeholder="Наприклад: замість 6 літрів пива візьмемо 12 банок по 0,5 л"
                                data-plan-correction-input
                            >{{ old('correction') }}</textarea>
                            @error('correction')
                                <p class="mt-2 text-sm font-bold text-orange-dark">{{ $message }}</p>
                            @enderror
                            @error('plan_state_version')
                                <p class="mt-2 text-sm font-bold text-orange-dark">{{ $message }}</p>
                            @enderror
                            <button class="mt-4 w-full rounded-2xl bg-orange px-5 py-3.5 font-extrabold text-white shadow-[3px_3px_0_#F7C84B] transition hover:-translate-y-0.5 hover:bg-orange-dark focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-green sm:w-auto" type="submit">Передати корективу Гусю</button>
                        </form>
                    </div>

                    @if ($planIsCurrent && $hasBlockingQuestion)
                        <div class="mt-4 rounded-2xl border border-orange/35 bg-orange/10 p-4 text-sm font-bold text-orange-dark">
                            Є важливе питання про безпеку або кількість. Спершу потрібна людська відповідь.
                            <a class="ml-1 underline decoration-2 underline-offset-4" href="{{ route('events.show', ['event' => $event, 'tab' => 'questions']) }}">Відповісти Гусю</a>
                        </div>
                    @endif

                    @if ($event->plan_generation_status === \App\PlanGenerationStatus::Failed)
                        <div class="mt-5 rounded-2xl border border-orange/35 bg-orange/10 p-4">
                            <p class="text-sm font-bold text-orange-dark">Гусь перечепився, але попередній список цілий.</p>
                            <form class="mt-3" method="POST" action="{{ route('events.analysis.store', $event) }}" data-analysis-form>
                                @csrf
                                <button class="rounded-xl bg-orange px-4 py-2.5 text-sm font-extrabold text-white" type="submit" data-analysis-button>Гусь, спробуй ще раз</button>
                            </form>
                        </div>
                    @elseif (! $planIsCurrent)
                        <p class="mt-5 rounded-2xl bg-yellow/45 p-4 text-sm font-bold">Гусь почув нове й уже перераховує. Поки залишаємо попередній список перед очима.</p>
                    @endif

                    <p class="mt-5 text-base leading-7 text-muted">{{ $plan['summary'] }}</p>
                    @if ($plan['serves'] ?? null)
                        <p class="mt-2 text-sm font-bold text-green-dark">Розраховано на {{ $plan['serves'] }} людей</p>
                    @endif

                    <div class="mt-6 grid gap-5 lg:grid-cols-2">
                        @foreach ($categoryLabels as $category => $label)
                            @php $categoryItems = $planItems->where('category', $category); @endphp
                            @if ($categoryItems->isNotEmpty())
                                <section class="rounded-[22px] bg-canvas p-4">
                                    <h3 class="font-display text-2xl">{{ $label }}</h3>
                                    <ul class="mt-3 divide-y divide-ink/10">
                                        @foreach ($categoryItems as $item)
                                            <li class="flex items-start justify-between gap-4 py-3 first:pt-0 last:pb-0">
                                                <div>
                                                    <p class="font-extrabold">{{ $item['name'] }}</p>
                                                    @if ($item['note'] !== '')
                                                        <p class="mt-1 text-xs leading-5 text-muted">{{ $item['note'] }}</p>
                                                    @endif
                                                </div>
                                                <p class="shrink-0 rounded-full bg-paper px-3 py-1 text-sm font-extrabold">{{ rtrim(rtrim(number_format((float) $item['quantity'], 2, '.', ''), '0'), '.') }} {{ $item['unit'] }}</p>
                                            </li>
                                        @endforeach
                                    </ul>
                                </section>
                            @endif
                        @endforeach
                    </div>

                    @if (($plan['warnings'] ?? []) !== [])
                        <div class="mt-5 rounded-2xl border border-orange/30 bg-orange/8 p-4">
                            <p class="font-extrabold text-orange-dark">Що варто перевірити</p>
                            <ul class="mt-2 space-y-2 text-sm leading-6">
                                @foreach ($plan['warnings'] as $warning)
                                    <li>→ {{ $warning }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endif
            </section>
        @elseif ($activeTab === 'silpo')
            <section class="mt-7 rounded-[30px] border-2 border-ink bg-paper p-5 shadow-[6px_7px_0_#F7C84B] sm:p-7">
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-green-dark">Справжній кошик</p>
                <h2 class="mt-1 font-display text-4xl">Кошик Сільпо</h2>
                @if ($latestCartRun)
                    <div class="mt-5 rounded-[22px] border-2 border-ink/15 bg-canvas p-5">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="font-display text-2xl">{{ $latestCartRun->status->label() }}</p>
                                <p class="mt-1 text-sm text-muted">
                                    {{ $latestCartRun->mode->label() }} ·
                                    @if ($completedCartProducts->isNotEmpty())
                                        Товарів у кошику: {{ $completedCartProducts->count() }}
                                    @else
                                        {{ $stagedCartProducts->count() }} позицій від Гуся
                                    @endif
                                </p>
                            </div>
                            @if ($latestCartRun->actual_total !== null)
                                <p class="rounded-full bg-yellow px-4 py-2 font-extrabold">{{ number_format((float) $latestCartRun->actual_total, 2, ',', ' ') }} ₴</p>
                            @endif
                        </div>
                    </div>

                    @if ($completedCartProducts->isNotEmpty())
                        <section class="mt-6" aria-labelledby="completed-cart-products-title">
                            <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between sm:gap-4">
                                <div>
                                    <h3 class="font-display text-3xl leading-[1.1]" id="completed-cart-products-title">Що Гусь додав</h3>
                                    <p class="mt-1 text-sm leading-6 text-muted">Реальні товари з останнього підтвердженого кошика Сільпо.</p>
                                </div>
                                <p class="shrink-0 text-sm font-extrabold text-green-dark">Товарів у кошику: {{ $completedCartProducts->count() }}</p>
                            </div>

                            <div class="mt-4 grid gap-3 md:grid-cols-2">
                                @foreach ($completedCartProducts as $product)
                                    @php
                                        $productImage = data_get($product, 'image');
                                        $productQuantity = (float) data_get($product, 'quantity', 0);
                                        $productPrice = (float) data_get($product, 'price', 0);
                                        $productTotal = (float) data_get(
                                            $product,
                                            'total',
                                            data_get($product, 'estimated_total', $productQuantity * $productPrice),
                                        );
                                    @endphp
                                    <article class="grid grid-cols-[4.5rem_minmax(0,1fr)] gap-4 rounded-[22px] border-2 border-ink/10 bg-canvas p-3 sm:p-4">
                                        <div class="grid size-[4.5rem] place-items-center overflow-hidden rounded-2xl bg-white text-2xl">
                                            @if (is_string($productImage) && \Illuminate\Support\Str::startsWith($productImage, 'https://'))
                                                <img class="size-full object-contain" src="{{ $productImage }}" alt="" loading="lazy">
                                            @else
                                                <span aria-hidden="true">🧺</span>
                                            @endif
                                        </div>
                                        <div class="min-w-0 self-center">
                                            <p class="text-sm font-extrabold leading-5">{{ data_get($product, 'name', 'Товар Сільпо') }}</p>
                                            <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-sm">
                                                <p class="text-muted">{{ rtrim(rtrim(number_format($productQuantity, 3, ',', ''), '0'), ',') }} × {{ number_format($productPrice, 2, ',', ' ') }} ₴</p>
                                                <p class="font-extrabold">{{ number_format($productTotal, 2, ',', ' ') }} ₴</p>
                                            </div>
                                        </div>
                                    </article>
                                @endforeach
                            </div>

                            @if (collect($latestCartRun->warnings)->isNotEmpty())
                                <div class="mt-4 rounded-[22px] border border-orange/30 bg-yellow/30 p-4">
                                    <p class="font-extrabold">Що варто перевірити на пакованні</p>
                                    <ul class="mt-2 space-y-2 text-sm leading-6">
                                        @foreach ($latestCartRun->warnings as $warning)
                                            <li>→ {{ $warning }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </section>
                    @endif
                @else
                    <p class="mt-4 text-base leading-7 text-muted">Гусь ще не ходив між прилавками для цієї події.</p>
                @endif

                @if ($plan !== [])
                    <button
                        class="mt-5 w-full rounded-2xl bg-green px-5 py-4 font-extrabold text-white shadow-[4px_4px_0_#20201D] transition enabled:hover:-translate-y-0.5 enabled:hover:bg-green-dark disabled:cursor-not-allowed disabled:opacity-45 focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-green sm:w-auto"
                        type="button"
                        data-silpo-dialog-open
                        @disabled(! $planIsCurrent || $hasBlockingQuestion)
                    >{{ $latestCartRun?->isActive() ? 'Повернутися до Гуся' : ($completedCartProducts->isNotEmpty() ? 'Зібрати кошик наново' : 'Відправити Гуся в Сільпо') }}</button>
                @endif
            </section>
        @endif

        @if ($plan !== [])
            <dialog
                class="m-auto h-[min(58rem,calc(100dvh-1rem))] w-[min(72rem,calc(100vw-1rem))] overflow-hidden rounded-[30px] border-2 border-ink bg-paper p-0 text-ink shadow-[9px_10px_0_#20201D] backdrop:bg-ink/60"
                data-silpo-dialog
                data-preflight-url="{{ route('events.silpo.cart-preflight', $event) }}"
                data-start-url="{{ route('events.cart-runs.store', $event) }}"
                @if ($latestCartRun) data-run-url="{{ route('events.cart-runs.show', [$event, $latestCartRun]) }}" @endif
                aria-labelledby="silpo-dialog-title"
            >
                <div class="flex h-full min-h-0 flex-col">
                    <header class="flex items-start justify-between gap-4 border-b-2 border-ink/10 bg-yellow/35 px-5 py-4 sm:px-7">
                        <div class="min-w-0">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-green-dark">Перед походом у Сільпо</p>
                            <h3 class="mt-1 font-display text-3xl leading-[1.1] sm:text-4xl" id="silpo-dialog-title">Новий похід — чистий кошик</h3>
                            <x-harness-ai-labels class="mt-2" />
                        </div>
                        <form method="dialog">
                            <button class="grid size-10 shrink-0 place-items-center rounded-full border-2 border-ink bg-paper text-xl font-bold transition hover:-translate-y-0.5 hover:bg-yellow focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-green" type="button" data-silpo-dialog-close data-silpo-dialog-minimize aria-label="Згорнути вікно кошика">−</button>
                        </form>
                    </header>

                    <div class="min-h-0 flex-1 overflow-y-auto p-4 sm:p-6" data-silpo-dialog-body>
                        <section class="grid min-h-72 place-items-center text-center" data-silpo-loading>
                            <div>
                                <img class="goose-working mx-auto size-28 object-contain" src="{{ asset('images/brand/goose-sho.png') }}" alt="Гусь Шо перевіряє кошик">
                                <p class="mt-3 font-display text-2xl" data-silpo-loading-message>Гусь готує безпечний старт…</p>
                            </div>
                        </section>

                        <section class="hidden min-h-72 place-items-center" data-silpo-reset>
                            <div class="grid max-w-4xl gap-5 rounded-[26px] border-2 border-ink bg-yellow/25 p-5 shadow-[6px_7px_0_#20201D] sm:grid-cols-[10rem_minmax(0,1fr)] sm:p-7">
                                <img class="mx-auto size-36 object-contain" src="{{ asset('images/brand/goose-sho.png') }}" alt="Гусь Шо готує чистий кошик">
                                <div>
                                    <p class="text-xs font-extrabold uppercase tracking-[0.15em] text-orange-dark">Перед будь-яким маршрутом</p>
                                    <h4 class="mt-1 font-display text-4xl leading-[1.05]">Спершу — чистий кошик</h4>
                                    <p class="mt-3 text-base leading-7 text-muted">Старі товари можуть засипати Гуся попередженнями про залишки, паковання й прострочений час. Після вашого підтвердження ми зашифровано збережемо повну копію нинішнього кошика, очистимо всі товари й одразу перевіримо, що він справді порожній.</p>
                                    <p class="mt-3 rounded-2xl bg-paper p-4 text-sm font-bold leading-6">Адресу, магазин і час потім треба обрати заново. Це підтвердження не дозволяє додавати товари, оформляти замовлення, платити або змінювати бонуси й промокоди.</p>
                                    <button class="mt-5 w-full rounded-2xl bg-orange px-5 py-4 font-extrabold text-white shadow-[4px_4px_0_#20201D] transition hover:-translate-y-0.5 hover:bg-orange-dark disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto" type="button" data-silpo-reset-confirm>Зберегти копію й очистити кошик</button>
                                </div>
                            </div>
                        </section>

                        <section class="hidden min-h-72 place-items-center" data-silpo-guard>
                            <div class="max-w-2xl rounded-[26px] border-2 border-orange/40 bg-orange/8 p-6 text-center sm:p-8">
                                <img class="mx-auto size-24 object-contain" src="{{ asset('images/brand/goose-sho.png') }}" alt="">
                                <h4 class="mt-3 font-display text-3xl" data-silpo-guard-title>Гусь уперся в зачинені двері</h4>
                                <p class="mt-3 text-base leading-7 text-muted" data-silpo-guard-message></p>
                                <div class="mt-5 flex flex-col justify-center gap-3 sm:flex-row">
                                    <a class="hidden rounded-2xl bg-green px-5 py-3.5 font-extrabold text-white shadow-[3px_3px_0_#20201D]" href="#" target="_blank" rel="noopener" data-silpo-guard-action></a>
                                    <button class="rounded-2xl border-2 border-ink bg-paper px-5 py-3.5 font-extrabold transition hover:bg-yellow/35" type="button" data-silpo-recheck>Гусь, перевір іще раз</button>
                                </div>
                            </div>
                        </section>

                        <section class="hidden" data-silpo-fulfilment>
                            <button class="mb-4 hidden rounded-xl border-2 border-ink bg-paper px-4 py-2 text-sm font-extrabold transition hover:bg-yellow/35" type="button" data-silpo-route-home>До розмови з Гусем</button>

                            <div data-silpo-fulfilment-content aria-live="polite">
                                <div class="mb-4">
                                    <h5 class="font-display text-3xl leading-[1.1]">Скажіть Гусю, куди й як доставити</h5>
                                    <p class="mt-2 max-w-3xl text-sm leading-6 text-muted">Оберіть місце, магазин і час заново. Спершу Гусь розбере фразу, потім Сільпо окремо підтвердить кожну частину маршруту.</p>
                                </div>
                            </div>

                            <section class="mt-5 hidden rounded-[24px] border-2 border-green bg-green-soft/20 p-4 sm:p-5" data-silpo-route-review>
                                <p class="text-xs font-extrabold uppercase tracking-[0.15em] text-green-dark">Гусь усе занотував</p>
                                <h5 class="mt-1 font-display text-3xl">Оцей маршрут — і лише оцей</h5>
                                <dl class="mt-4 grid gap-3 sm:grid-cols-2" data-silpo-review-summary></dl>
                                <p class="mt-4 text-sm leading-6 text-muted">Після натискання Гусь ще раз звірить маршрут із Сільпо. Якщо хтось устиг щось змінити — зупиниться, а не влаштує доставковий сюрприз.</p>

                                <fieldset class="mt-5 grid gap-3 lg:grid-cols-2">
                                    <legend class="mb-2 font-display text-2xl">Як Гусю шукати товари?</legend>
                                    <label class="block cursor-pointer rounded-2xl border-2 border-green bg-paper p-4 has-checked:bg-green-soft/45">
                                        <span class="flex items-center gap-3 font-extrabold"><input class="size-4 accent-green" type="radio" name="silpo-mode" value="assisted" checked> З підстраховкою</span>
                                        <span class="mt-1 block pl-7 text-sm leading-5 text-muted">Спитає лише коли пристойної заміни справді немає.</span>
                                    </label>
                                    <label class="block cursor-pointer rounded-2xl border-2 border-ink/15 bg-paper p-4 has-checked:border-green has-checked:bg-green-soft/25">
                                        <span class="flex items-center gap-3 font-extrabold"><input class="size-4 accent-green" type="radio" name="silpo-mode" value="auto"> Повний автопілот</span>
                                        <span class="mt-1 block pl-7 text-sm leading-5 text-muted">Сам обере рольові заміни й позначить усе сумнівне для фінальної перевірки.</span>
                                    </label>
                                </fieldset>

                                <button class="mt-5 w-full rounded-2xl bg-green px-5 py-4 font-extrabold text-white shadow-[4px_4px_0_#20201D] transition hover:-translate-y-0.5 hover:bg-green-dark disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto" type="button" data-silpo-start>Гусю, маршрут є — лети збирати кошик</button>
                            </section>
                        </section>

                        <section class="hidden" data-silpo-run>
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs font-extrabold uppercase tracking-[0.15em] text-green-dark" data-silpo-mode-label></p>
                                    <h4 class="mt-1 font-display text-3xl" data-silpo-status-label>Гусь збирає кошик</h4>
                                </div>
                                <p class="rounded-full bg-yellow px-4 py-2 text-sm font-extrabold" data-silpo-progress-label>0%</p>
                            </div>
                            <div class="mt-3 h-3 overflow-hidden rounded-full bg-ink/10"><div class="h-full rounded-full bg-orange transition-[width] duration-500" style="width: 0%" data-silpo-progress></div></div>

                            <div class="mt-5 grid min-h-0 gap-5 lg:grid-cols-[minmax(0,0.95fr)_minmax(22rem,1.05fr)]">
                                <section class="rounded-[22px] bg-ink p-4 text-paper sm:p-5">
                                    <div class="flex items-center justify-between gap-3">
                                        <h5 class="font-display text-2xl text-yellow">Що там відбувається</h5>
                                        <span class="size-2.5 animate-pulse rounded-full bg-green-soft" aria-hidden="true" data-silpo-live-dot></span>
                                    </div>
                                    <ol class="mt-4 max-h-[28rem] space-y-3 overflow-y-auto pr-2 text-sm leading-6" data-silpo-steps aria-live="polite"></ol>
                                </section>

                                <section class="rounded-[22px] border-2 border-ink/15 bg-canvas p-4 sm:p-5">
                                    <div class="flex items-center justify-between gap-3">
                                        <h5 class="font-display text-2xl">Тимчасовий кошик</h5>
                                        <p class="text-sm font-extrabold text-green-dark" data-silpo-staged-total>0,00 ₴</p>
                                    </div>
                                    <div class="mt-4 grid max-h-[23rem] gap-3 overflow-y-auto pr-1" data-silpo-staged-items>
                                        <p class="rounded-2xl border border-dashed border-ink/20 bg-paper p-4 text-sm text-muted" data-silpo-staged-empty>Поки порожньо. Гусь лише зайшов.</p>
                                    </div>
                                    <details class="mt-4 rounded-2xl bg-paper p-4">
                                        <summary class="cursor-pointer text-sm font-extrabold">Стартовий кошик після очищення <span data-silpo-existing-badge></span></summary>
                                        <div class="mt-3 grid gap-2" data-silpo-existing-items></div>
                                    </details>
                                </section>
                            </div>

                            <div class="mt-5 hidden rounded-[22px] border-2 border-orange/35 bg-orange/8 p-4" data-silpo-blocker>
                                <p class="font-display text-2xl">Гусю справді потрібна підказка</p>
                                <p class="mt-2 text-sm leading-6 text-muted" data-silpo-blocker-message></p>
                                <div class="mt-3 flex flex-col gap-3 sm:flex-row">
                                    <input class="min-w-0 flex-1 rounded-2xl border border-ink/20 bg-paper px-4 py-3 outline-none focus:border-green focus:ring-3 focus:ring-green/15" type="text" maxlength="1000" placeholder="Напишіть коротко, що робити" data-silpo-answer>
                                    <button class="rounded-2xl bg-orange px-5 py-3 font-extrabold text-white" type="button" data-silpo-continue>Підказати Гусю</button>
                                </div>
                            </div>

                            <div class="mt-5 hidden rounded-[22px] border-2 border-green bg-green-soft/20 p-5" data-silpo-confirmation>
                                <p class="font-display text-3xl" data-silpo-confirm-title>Останній людський погляд</p>
                                <p class="mt-2 text-sm leading-6 text-muted" data-silpo-confirm-copy>Перевірте реальні товари Сільпо, рольові заміни, знаки питання щодо паковання, кількості й суму. До цього підтвердження Гусь нічого в кошик не записує.</p>
                                <button class="mt-4 w-full rounded-2xl bg-green px-5 py-4 font-extrabold text-white shadow-[4px_4px_0_#20201D] transition hover:-translate-y-0.5 hover:bg-green-dark disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto" type="button" data-silpo-confirm>Підтверджую товари — додати в кошик</button>
                            </div>

                            <div class="mt-5 hidden rounded-[22px] border border-orange/30 bg-yellow/30 p-4" data-silpo-warnings>
                                <p class="font-extrabold">Що треба перевірити</p>
                                <ul class="mt-2 space-y-2 text-sm leading-6" data-silpo-warning-list></ul>
                            </div>
                        </section>
                    </div>
                </div>
            </dialog>

            <aside
                class="fixed inset-x-4 bottom-4 z-50 hidden rounded-[24px] border-2 border-ink bg-paper p-4 shadow-[6px_7px_0_#20201D] sm:inset-x-auto sm:right-5 sm:w-[min(22rem,calc(100vw-2.5rem))]"
                data-silpo-dialog-minimized
                aria-live="polite"
                aria-atomic="true"
            >
                <div class="flex items-start gap-3">
                    <img class="goose-working -ml-1 size-16 shrink-0 object-contain" src="{{ asset('images/brand/goose-sho.png') }}" alt="">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-2">
                            <p class="font-display text-xl leading-[1.15]" data-silpo-minimized-title>Гусь працює</p>
                            <button class="grid size-8 shrink-0 place-items-center rounded-full bg-canvas text-base font-extrabold transition hover:bg-yellow focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-green" type="button" data-silpo-dialog-restore aria-label="Розгорнути вікно кошика">+</button>
                        </div>
                        <p class="mt-1 text-sm leading-5 text-muted" data-silpo-minimized-status>Гусь готує безпечний старт…</p>
                        <div class="mt-3 hidden h-2 overflow-hidden rounded-full bg-ink/10" data-silpo-minimized-progress-wrap>
                            <div class="h-full rounded-full bg-orange transition-[width] duration-500" style="width: 0%" data-silpo-minimized-progress></div>
                            <span class="sr-only" data-silpo-minimized-progress-label>0%</span>
                        </div>
                    </div>
                </div>
            </aside>
        @endif
    </div>

    <dialog
        class="m-auto h-[min(58rem,calc(100dvh-1rem))] w-[min(72rem,calc(100vw-1rem))] overflow-hidden rounded-[30px] border-2 border-ink bg-paper p-0 text-ink shadow-[9px_10px_0_#20201D] backdrop:bg-ink/60"
        data-analysis-overlay
        data-analysis-id="{{ $event->analysis_task_id }}"
        data-analysis-stage="{{ $event->analysis_stage?->value }}"
        data-analysis-started-at="{{ $event->analysis_started_at?->toISOString() }}"
        data-analysis-progress-value="{{ $analysisProgress }}"
        data-analysis-message-value="{{ $analysisMessage }}"
        data-analysis-error-value="{{ $event->analysis_error }}"
        aria-labelledby="analysis-dialog-title"
    >
        <div class="flex h-full min-h-0 flex-col">
            <header class="flex items-start justify-between gap-4 border-b-2 border-ink/10 bg-yellow/35 px-5 py-4 sm:px-7">
                <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-green-dark">Новий матеріал уже під крилом</p>
                    <h3 class="mt-1 font-display text-3xl leading-[1.1] sm:text-4xl" id="analysis-dialog-title">Гусь розгрібає контекст</h3>
                    <x-harness-ai-labels class="mt-2" />
                </div>
                <div class="flex shrink-0 items-center gap-2 sm:gap-3">
                    <p class="rounded-full bg-paper/75 px-3 py-2 text-xs font-extrabold tabular-nums text-green-dark sm:text-sm">
                        <span class="sr-only sm:not-sr-only">Минуло </span><span data-analysis-elapsed aria-label="Минуло часу">00:00</span>
                    </p>
                    <form method="dialog">
                        <button class="grid size-10 shrink-0 place-items-center rounded-full border-2 border-ink bg-paper text-xl font-bold transition hover:-translate-y-0.5 hover:bg-yellow focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-green" type="button" data-analysis-minimize aria-label="Згорнути вікно контексту">−</button>
                    </form>
                </div>
            </header>

            <div class="grid min-h-0 flex-1 grid-cols-[minmax(7.5rem,0.72fr)_minmax(0,1.28fr)] gap-3 p-3 sm:grid-cols-[minmax(15rem,0.85fr)_minmax(0,1.15fr)] sm:gap-6 sm:p-6">
                <section class="relative flex min-h-0 flex-col items-center justify-center overflow-hidden rounded-[24px] bg-yellow/30 px-2 py-4 sm:px-5">
                    <span class="absolute left-3 top-3 -rotate-2 rounded-sm bg-paper px-3 py-1 font-display text-base leading-[1.15] shadow-[2px_2px_0_#F7C84B] sm:left-5 sm:top-5 sm:text-xl">Не заважайте, він красивий</span>
                    <img class="goose-working h-[min(23rem,54dvh)] w-full max-w-[21rem] object-contain sm:h-[min(34rem,68dvh)]" src="{{ asset('images/brand/goose-sho.png') }}" alt="Гусь Шо працює з новим контекстом">
                    <p class="relative z-10 -mt-2 max-w-sm rounded-2xl bg-paper/85 px-3 py-2 text-center text-xs font-bold leading-5 text-green-dark shadow-[2px_2px_0_#F7C84B] sm:text-sm">Великий чат може зайняти кілька хвилин. Вікно можна згорнути — Гусь не загубиться.</p>
                </section>

                <section class="flex min-h-0 flex-col rounded-[24px] bg-ink p-4 text-paper sm:p-6">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-extrabold uppercase tracking-[0.15em] text-green-soft">Що там відбувається</p>
                            <h4 class="mt-1 font-display text-2xl leading-[1.15] text-yellow sm:text-3xl" data-analysis-status-title>Гусь працює</h4>
                        </div>
                        <span class="mt-2 size-2.5 animate-pulse rounded-full bg-green-soft" aria-hidden="true" data-analysis-live-dot></span>
                    </div>

                    <ol class="mt-5 min-h-0 flex-1 space-y-3 overflow-y-auto pr-2 text-sm leading-6 sm:text-base" data-analysis-steps aria-live="polite"></ol>

                    <div class="mt-5 h-3 shrink-0 overflow-hidden rounded-full bg-paper/15">
                        <div class="h-full rounded-full bg-orange transition-[width] duration-500" style="width: {{ $analysisProgress }}%" data-analysis-progress></div>
                    </div>
                    <span class="sr-only" data-analysis-progress-label>{{ $analysisProgress }}%</span>
                </section>
            </div>
        </div>
    </dialog>

    <aside
        class="fixed inset-x-4 bottom-4 z-50 hidden rounded-[24px] border-2 border-ink bg-paper p-4 shadow-[6px_7px_0_#20201D] sm:inset-x-auto sm:right-5 sm:w-[min(22rem,calc(100vw-2.5rem))]"
        data-analysis-minimized
        aria-live="polite"
        aria-atomic="true"
    >
        <div class="flex items-start gap-3">
            <img class="goose-working -ml-1 size-16 shrink-0 object-contain" src="{{ asset('images/brand/goose-sho.png') }}" alt="">
            <div class="min-w-0 flex-1">
                <div class="flex items-start justify-between gap-2">
                    <p class="font-display text-xl leading-[1.15]" data-analysis-minimized-title>Гусь працює</p>
                    <button class="grid size-8 shrink-0 place-items-center rounded-full bg-canvas text-base font-extrabold transition hover:bg-yellow focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-green" type="button" data-analysis-restore aria-label="Розгорнути вікно контексту">+</button>
                </div>
                <p class="mt-1 text-sm leading-5 text-muted" data-analysis-minimized-status>{{ $analysisMessage }}</p>
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-ink/10">
                    <div class="h-full rounded-full bg-orange transition-[width] duration-500" style="width: {{ $analysisProgress }}%" data-analysis-minimized-progress></div>
                    <span class="sr-only" data-analysis-minimized-progress-label>{{ $analysisProgress }}%</span>
                </div>
            </div>
        </div>
    </aside>
</x-layouts.app>
