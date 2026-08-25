<?php

namespace App\Services;

use App\CartProductEvidence;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class CartCandidateSuitability
{
    private const MAX_DIRECT_CATALOG_SCOPE_ATTEMPTS = 3;

    /**
     * @param  array<string, mixed>  $need
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $eventContext
     * @param  array<string, mixed>  $shoppingPlan
     */
    public function allows(
        array $need,
        array $candidate,
        array $eventContext,
        array $shoppingPlan,
    ): bool {
        $needText = $this->text([
            data_get($need, 'name'),
            data_get($need, 'product_name'),
            data_get($need, 'purpose'),
            data_get($need, 'note'),
        ]);
        $productText = $this->text([
            data_get($candidate, 'name'),
            data_get($candidate, 'slug'),
        ]);
        $candidateText = $this->text([
            $productText,
            data_get($candidate, 'catalog_scope.slug'),
            data_get($candidate, 'catalog_scope.label'),
        ]);
        $policyText = $this->text([
            data_get($eventContext, 'summary'),
            data_get($shoppingPlan, 'summary'),
            ...data_get($shoppingPlan, 'warnings', []),
            $needText,
        ]);
        $detailText = array_key_exists('details', $candidate)
            ? $this->structuredText(data_get($candidate, 'details'))
            : '';
        $candidateEvidenceText = $this->text([$candidateText, $detailText]);

        if ($this->containsAny($policyText, ['без арахіс', 'нічого з арахіс'])) {
            $statesPeanutAbsence = $this->containsAny($candidateEvidenceText, [
                'не містить арахіс', 'без арахіс', 'no peanut', 'peanut free',
            ]);
            $statesPeanutRisk = $this->containsAny($candidateEvidenceText, [
                'може містити арахіс', 'may contain peanut',
            ]);

            if ($statesPeanutRisk
                || ($this->containsAny($candidateEvidenceText, ['арахіс', 'peanut', 'сатай', 'satay'])
                    && ! $statesPeanutAbsence)) {
                return false;
            }
        }

        if ($this->containsAny((string) data_get($need, 'category'), ['food', 'water', 'soft_drinks'])
            && $this->containsAny($candidateText, [
                'lego', 'конструктор', 'іграш', 'пазл', 'настільн', 'книга', 'журнал',
                'розмальов', 'шампун', 'крем для', 'гель для душ', 'зубн', 'дезодорант',
                'праль', 'миюч', 'засіб для', 'корм для', 'наповнювач',
                'картридж', 'решітк', 'шампур', 'пальник',
            ])) {
            return false;
        }

        if ($this->isProduceNeed($needText)
            && $this->containsAny($candidateText, [
                'свин', 'ялов', 'телят', 'курят', 'індич', 'ковбас', 'сосиск', 'кабанос',
                'мʼяс', "м'яс", 'риб', 'кревет', 'бекон', 'шинка', 'балик', 'паштет',
                'ryba', 'mias', 'svyn', 'yalov', 'teliat',
                'чипс', 'сухофрукт', 'родзин', 'насіння', 'снек', 'цукат',
            ])) {
            return false;
        }

        if (in_array(Str::lower((string) data_get($need, 'unit')), ['кг', 'kg', 'г', 'g'], true)
            && array_key_exists('weighted', $candidate)
            && data_get($candidate, 'weighted') !== true
            && ! $this->containsAny(Str::lower((string) data_get($candidate, 'display_ratio')), [
                'кг', 'kg', 'г', ' g', 'гр',
            ])) {
            return false;
        }

        if (data_get($need, 'category') === 'soft_drinks') {
            $displayRatio = Str::lower((string) data_get($candidate, 'display_ratio'));
            $isExplicitlyNonAlcoholic = $this->containsAny($candidateText, ['безалкогол']);
            $isAlcoholStyle = ! $isExplicitlyNonAlcoholic
                && $this->containsAny($candidateText, ['винн', 'пив', 'сидр', 'алкогол']);

            if (! preg_match('/\d+(?:[.,]\d+)?\s*(?:л|мл)(?:$|[^\p{L}])/u', $displayRatio)
                || $isAlcoholStyle) {
                return false;
            }
        }

        if (! $this->hasSufficientStock($need, $candidate)) {
            return false;
        }

        if ($this->isProduceNeed($needText)
            && (float) data_get($need, 'quantity', 0) >= 0.5
            && ! $this->sharesNeedIdentityTerm($need, $candidateText)
            && $this->containsAny($candidateText, [
                'часник', 'цибул', 'імбир', 'хрін',
            ])) {
            return false;
        }

        if (! $this->sharesCatalogTerm($need, $candidateText)
            && ! (data_get($candidate, 'catalog_scope.matched') === true
                && data_get($candidate, 'catalog_scope.type') === 'category')
            && ! ($this->isLeafySaladNeed($needText) && $this->isLeafySaladCandidate($productText))
            && ! $this->allowsBroadProduceRoleCandidate($need)) {
            return false;
        }

        if ($this->containsAny($policyText, ['без'])
            && $this->containsAny($policyText, ['готових маринад', 'готового маринад'])
            && $this->containsAny($candidateText, ['маринован', 'маринад'])) {
            return false;
        }

        if ($this->containsAny($needText, ['солодк', 'болгар'])
            && $this->containsAny($candidateText, ['чилі', 'гостр', 'пекуч', 'халапень'])) {
            return false;
        }

        if ($this->containsAny($needText, ['для грил', 'на грил', 'для мангал', 'на мангал'])
            && ! $this->containsAny($needText, ['чилі', 'гостр', 'пекуч', 'халапень'])
            && $this->containsAny($candidateText, ['чилі', 'гостр', 'пекуч', 'халапень'])) {
            return false;
        }

        if ($this->containsAny($needText, ['шашлик']) && $this->containsAny($needText, ['свинин'])) {
            $isPrepared = $this->containsAny($candidateText, [
                'ковбас', 'шашлик', 'напівфабрикат', 'маринован', 'копчен', 'запечен',
            ]);

            if ($isPrepared) {
                return false;
            }
        }

        if (($this->isProduceNeed($needText)
            || $this->containsAny($needText, [
                'свіж', 'для грил', 'на мангал', 'для мангал', 'салатні лист', 'листя салат', 'зелень',
            ]))
            && ($this->containsAny($candidateText, [
                'маринован', 'солон', 'малосоль', 'квашен', 'кімчі', 'стерилізован',
                'консервован', 'фарширован', 'в олії', 'по-корейськи', 'соус', 'ікра', 'гриль',
                'олад', 'дерун', 'котлет', 'млинц', 'рагу', 'закуск', 'салат з', 'салат овочев', 'по-',
                'смажен', 'тушкован', 'запечен', 'варен', 'відварен', 'готов', 'напівфабрикат',
                'сушен', 'вʼялен', "в'ялен", 'суміш', 'асорті', 'пюре', 'порош', 'сублімован',
                'нарізан',
                'мелен', 'духмян', 'приправа', 'спеці', 'гостр', 'прянощ',
                'лечо',
                'з томат', 'з часник', 'з сир', 'з овоч', 'в аджиц',
            ]) || preg_match('/(?:^|[\s-])різан/u', $candidateText) === 1)) {
            return false;
        }

        if ($this->isProduceNeed($needText)
            && $this->containsAny($productText, ['салат'])
            && ! $this->isLeafySaladCandidate($productText)) {
            return false;
        }

        if ($this->containsAny($needText, ['соус'])) {
            if ($this->containsAny($policyText, ['без молочн'])
                && $this->containsAny($candidateText, ['молочн', 'вершк', 'сметан', 'сирн', 'йогурт'])) {
                return false;
            }

            if ($this->containsAny($policyText, ['без майонез', 'майонезу'])
                && $this->containsAny($candidateText, ['майонез'])) {
                return false;
            }

            if (array_key_exists('details', $candidate)) {
                if ($this->containsAny($policyText, ['без молочн'])) {
                    $statesDairyAbsence = $this->containsAny($detailText, [
                        'без молочн', 'без лактоз', 'веган', 'vegan', 'dairy free',
                    ]);

                    if (! $statesDairyAbsence && $this->containsAny($detailText, [
                        'молочн', 'молок', 'вершк', 'сметан', 'сирн', 'йогурт', 'лактоз',
                    ])) {
                        return false;
                    }
                }
            }
        }

        $isExhaustedFreshProduceFallback = count(data_get($need, 'browse_attempts', [])) >= 2
            && data_get($candidate, 'catalog_scope.matched') === true
            && data_get($candidate, 'catalog_scope.type') === 'category'
            && $this->isProduceNeed($candidateText);

        if ($this->isLeafySaladNeed($needText)
            && ! $isExhaustedFreshProduceFallback
            && ! $this->isLeafySaladCandidate($productText)) {
            return false;
        }

        if ($this->containsAny($needText, ['зелень'])
            && ! $this->containsAny($candidateText, [
                'зелень', 'кріп', 'петруш', 'кінз', 'коріандр', 'базилік', 'мʼят', "м'ят",
                'мікрогрін', 'рукол', 'шпинат', 'цибуля зелена',
            ])) {
            return false;
        }

        if ($this->containsAny($needText, ['серветк'])
            && $this->containsAny($needText, ['одноразов', 'столов', 'папер'])
            && $this->containsAny($candidateText, [
                'косметич', 'волог', 'для обличч', 'для прибиран', 'мікрофібр', 'віскоз', 'універсальн',
                'гігієн', 'інтим', 'носов', 'хустин', 'туалет', 'антисепт', 'дитяч', 'baby',
            ])) {
            return false;
        }

        if ($this->containsAny($needText, ['безглютен'])
            && array_key_exists('details', $candidate)
            && ! $this->containsAny($candidateEvidenceText, ['безглютен', 'gluten free'])
            && $this->containsAny($candidateEvidenceText, [
                'містить глютен', 'пшеничн', 'ячмін', 'житн', 'wheat',
            ])) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $need
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $eventContext
     * @param  array<string, mixed>  $shoppingPlan
     * @return array{selectable: bool, match: string, safety: string, review_note: ?string}
     */
    public function evidence(
        array $need,
        array $candidate,
        array $eventContext,
        array $shoppingPlan,
        ?string $modelSafetyEvidence = null,
        bool $modelReplacement = false,
    ): array {
        $selectable = $this->allows($need, $candidate, $eventContext, $shoppingPlan);
        $candidateText = $this->text([
            data_get($candidate, 'name'),
            data_get($candidate, 'slug'),
        ]);
        $match = ! $modelReplacement && $this->sharesNeedIdentityTerm($need, $candidateText)
            ? CartProductEvidence::MATCH_EXACT
            : CartProductEvidence::MATCH_SAME_ROLE;
        $safety = CartProductEvidence::SAFETY_NOT_REQUIRED;

        if ($this->requiresInspection($need, $eventContext)) {
            $safety = array_key_exists('details', $candidate)
                && $this->hasConclusiveSafetyEvidence($need, $candidate, $eventContext)
                    ? CartProductEvidence::SAFETY_VERIFIED
                    : CartProductEvidence::SAFETY_UNVERIFIED;
        }

        if ($safety === CartProductEvidence::SAFETY_NOT_REQUIRED
            && ! $this->isClearlySimpleFood($need, $candidate)
            && in_array($modelSafetyEvidence, [
                CartProductEvidence::SAFETY_VERIFIED,
                CartProductEvidence::SAFETY_UNVERIFIED,
            ], true)) {
            $safety = $modelSafetyEvidence;
        }

        $notes = [];

        if ($match === CartProductEvidence::MATCH_SAME_ROLE) {
            $notes[] = 'Заміна для «'.data_get($need, 'name').'»: найближчий доступний товар у тій самій ролі.';
        }

        if ($safety === CartProductEvidence::SAFETY_UNVERIFIED) {
            $notes[] = '❓ Гусь не знайшов повних даних про алергени чи склад на сторінці товару. Перевірте паковання перед подачею.';
        }

        return [
            'selectable' => $selectable,
            'match' => $match,
            'safety' => $safety,
            'review_note' => $notes === [] ? null : implode(' ', $notes),
        ];
    }

    /** @param array<string, mixed> $need @param array<string, mixed> $candidate */
    public function isExactIdentityCandidate(array $need, array $candidate): bool
    {
        return $this->sharesNeedIdentityTerm($need, $this->text([
            data_get($candidate, 'name'),
            data_get($candidate, 'slug'),
        ]));
    }

    /** @param array<string, mixed> $need @param array<int, array<string, mixed>> $candidates */
    public function hasExactIdentityCandidate(array $need, array $candidates): bool
    {
        return collect($candidates)->contains(
            fn (array $candidate): bool => $this->isExactIdentityCandidate($need, $candidate),
        );
    }

    /** @param array<string, mixed> $need @param array<string, mixed> $candidate */
    public function allowsProductReuseForNeed(array $need, array $candidate): bool
    {
        $needText = $this->text([
            data_get($need, 'name'),
            data_get($need, 'product_name'),
            data_get($need, 'purpose'),
            data_get($need, 'note'),
        ]);
        $candidateText = $this->text([
            data_get($candidate, 'name'),
            data_get($candidate, 'slug'),
            data_get($candidate, 'catalog_scope.slug'),
            data_get($candidate, 'catalog_scope.label'),
        ]);
        $scopeIdentityRoots = $this->scopeRoots($this->text([
            data_get($candidate, 'catalog_scope.slug'),
            data_get($candidate, 'catalog_scope.label'),
        ]));
        $exactNeedIdentity = $this->sharesNeedIdentityTerm($need, $candidateText)
            || array_intersect($this->scopeRoots($needText), $scopeIdentityRoots) !== [];
        $hasReachedRoleFallback = count(data_get($need, 'browse_attempts', [])) >= 1;

        return $this->isProduceNeed($needText)
            && $this->isProduceNeed($candidateText)
            && data_get($candidate, 'catalog_scope.type') === 'category'
            && data_get($candidate, 'catalog_scope.matched') === true
            && (count(data_get($need, 'attempts', [])) >= 6 || $this->nextSearchQuery($need) === null)
            && ($exactNeedIdentity || $hasReachedRoleFallback);
    }

    /**
     * @param  array<string, mixed>  $need
     * @param  array<string, mixed>  $eventContext
     */
    public function requiresInspection(array $need, array $eventContext): bool
    {
        $needName = $this->text([data_get($need, 'name')]);
        $needText = $this->text([
            data_get($need, 'name'),
            data_get($need, 'product_name'),
            data_get($need, 'purpose'),
            data_get($need, 'note'),
        ]);
        $constraintText = $this->text([
            data_get($eventContext, 'summary'),
            ...collect(data_get($eventContext, 'restrictions', []))
                ->map(fn (mixed $restriction): mixed => is_array($restriction)
                    ? data_get($restriction, 'restriction', data_get($restriction, 'value'))
                    : $restriction)
                ->all(),
        ]);

        if ($this->isClearlySimpleNeed($need)) {
            return false;
        }

        return $this->containsAny($needText, ['безглютен'])
            || $this->containsAny($needText, ['без цукру'])
            || $this->containsAny($needText, ['арахіс', 'peanut'])
            || ($this->containsAny($needName, ['соус'])
                && $this->containsAny($constraintText, ['арахіс', 'молочн', 'лактоз', 'целіак', 'глютен']));
    }

    /**
     * @param  array<string, mixed>  $need
     */
    public function nextSearchQuery(array $need): ?string
    {
        $attemptedQueries = collect(data_get($need, 'attempts', []))
            ->pluck('query')
            ->filter(fn (mixed $query): bool => is_string($query))
            ->map(fn (string $query): string => Str::lower($query));

        return collect($this->orderedSearchQueries($need))->first(
            fn (string $query): bool => ! $attemptedQueries->contains(Str::lower($query)),
        );
    }

    /**
     * @param  array<string, mixed>  $need
     * @param  array{categories?: array<int, array<string, mixed>>, sets?: array<int, array<string, mixed>>}  $catalogScopes
     * @return array<string, mixed>|null
     */
    public function nextCatalogScope(array $need, array $catalogScopes): ?array
    {
        $identityRootWeights = $this->scopeRootWeights([
            data_get($need, 'product_name'),
            data_get($need, 'name'),
            data_get($need, 'search_query'),
            ...data_get($need, 'search_queries', []),
        ], 3);
        $contextRootWeights = $this->scopeRootWeights([
            data_get($need, 'note'),
            data_get($need, 'category'),
        ], 1);
        $attemptedScopes = collect(data_get($need, 'browse_attempts', []))
            ->map(fn (mixed $attempt): string => is_array($attempt)
                ? data_get($attempt, 'type').':'.data_get($attempt, 'slug')
                : '')
            ->filter();
        $availableScopes = collect([
            ...data_get($catalogScopes, 'categories', []),
            ...data_get($catalogScopes, 'sets', []),
        ])
            ->filter(fn (mixed $scope): bool => is_array($scope)
                && in_array(data_get($scope, 'type'), ['category', 'set'], true)
                && filled(data_get($scope, 'slug')));
        $scopeIndex = $availableScopes
            ->filter(fn (array $scope): bool => data_get($scope, 'type') === 'category')
            ->keyBy(fn (array $scope): string => (string) data_get($scope, 'slug'))
            ->all();
        $latestAttempt = collect(data_get($need, 'browse_attempts', []))->last();

        if (is_array($latestAttempt)
            && data_get($latestAttempt, 'type') === 'category'
            && (int) data_get($latestAttempt, 'total_found', 0) === 0) {
            $attemptedCategory = $availableScopes->first(
                fn (array $scope): bool => data_get($scope, 'type') === 'category'
                    && data_get($scope, 'slug') === data_get($latestAttempt, 'slug'),
            );
            $parentSlug = data_get($attemptedCategory, 'parent_slug');
            $parentScope = is_string($parentSlug) && $parentSlug !== ''
                ? $availableScopes->first(
                    fn (array $scope): bool => data_get($scope, 'type') === 'category'
                        && data_get($scope, 'slug') === $parentSlug,
                )
                : null;

            if (is_array($parentScope)
                && (int) data_get($attemptedCategory, 'depth', 0) >= 2
                && ! $attemptedScopes->contains('category:'.$parentSlug)
                && $this->catalogScopeAllowsNeed($need, $parentScope, $scopeIndex)) {
                return $parentScope;
            }
        }

        $rankedScopes = $availableScopes
            ->filter(fn (array $scope): bool => $this->catalogScopeAllowsNeed($need, $scope, $scopeIndex))
            ->reject(fn (array $scope): bool => $attemptedScopes->contains(
                data_get($scope, 'type').':'.data_get($scope, 'slug'),
            ))
            ->map(function (array $scope) use ($contextRootWeights, $identityRootWeights, $need, $scopeIndex): array {
                $scopeRoots = $this->scopeRoots($this->text([
                    data_get($scope, 'slug'),
                    data_get($scope, 'label'),
                ]));
                $score = collect($scopeRoots)->sum(
                    fn (string $root): int => (int) data_get($identityRootWeights, $root, 0)
                        + (int) data_get($contextRootWeights, $root, 0),
                );

                return [
                    ...$scope,
                    '_match_score' => $score,
                    '_role_score' => $this->fallbackCatalogScopeScore($need, $scope, $scopeIndex),
                ];
            });
        $directScope = null;

        if (count(data_get($need, 'browse_attempts', [])) < self::MAX_DIRECT_CATALOG_SCOPE_ATTEMPTS) {
            $directScope = $rankedScopes
                ->filter(fn (array $scope): bool => $scope['_match_score'] > 0)
                ->sort(fn (array $left, array $right): int => [
                    -$left['_match_score'],
                    data_get($left, 'type') === 'category' ? 0 : 1,
                    -(int) data_get($left, 'depth', 0),
                    mb_strlen((string) data_get($left, 'slug')),
                ] <=> [
                    -$right['_match_score'],
                    data_get($right, 'type') === 'category' ? 0 : 1,
                    -(int) data_get($right, 'depth', 0),
                    mb_strlen((string) data_get($right, 'slug')),
                ])
                ->map(fn (array $scope): array => Arr::except($scope, ['_match_score', '_role_score']))
                ->first();
        }

        if (is_array($directScope)) {
            return $directScope;
        }

        return $rankedScopes
            ->filter(fn (array $scope): bool => $scope['_role_score'] > 0)
            ->sort(fn (array $left, array $right): int => [
                -$left['_role_score'],
                -(int) data_get($left, 'depth', 0),
                -(int) data_get($left, 'total', 0),
                mb_strlen((string) data_get($left, 'slug')),
            ] <=> [
                -$right['_role_score'],
                -(int) data_get($right, 'depth', 0),
                -(int) data_get($right, 'total', 0),
                mb_strlen((string) data_get($right, 'slug')),
            ])
            ->map(fn (array $scope): array => Arr::except($scope, ['_match_score', '_role_score']))
            ->first();
    }

    /**
     * @param  array<string, mixed>  $need
     * @param  array<string, mixed>  $scope
     */
    private function catalogScopeAllowsNeed(array $need, array $scope, array $scopeIndex = []): bool
    {
        $needText = $this->text([
            data_get($need, 'name'),
            data_get($need, 'product_name'),
            data_get($need, 'purpose'),
            data_get($need, 'note'),
        ]);
        $scopeText = $this->catalogScopePathText($scope, $scopeIndex);
        $requiresRawForm = $this->isProduceNeed($needText) || $this->containsAny($needText, [
            'свіж', 'сирий', 'сира', 'грил', 'мангал', 'шашлик', 'стейк',
        ]);

        if (data_get($need, 'category') === 'food'
            && Str::contains($scopeText, [
                'mangaly', 'reshitky', 'shampuri', 'palyvo', 'bady', 'cbd', 'funktsionalni',
                'dogliad', 'krasa', 'pobut', 'posud', 'prybyrannia',
            ])) {
            return false;
        }

        if (data_get($scope, 'type') === 'category'
            && $this->isProduceNeed($needText)
            && ! Str::contains($scopeText, ['frukty-ovochi'])) {
            return false;
        }

        return ! ($requiresRawForm && Str::contains($scopeText, [
            'kopchen', 'marynov', 'gotov', 'prygotov', 'konserv', 'zamoro', 'napivfabr', 'sushen',
            'zakusky', 'morska-kapusta',
        ]));
    }

    /**
     * @param  array<string, mixed>  $need
     * @param  array<string, mixed>  $scope
     * @param  array<string, array<string, mixed>>  $scopeIndex
     */
    private function fallbackCatalogScopeScore(array $need, array $scope, array $scopeIndex): int
    {
        $needText = $this->text([
            data_get($need, 'name'),
            data_get($need, 'product_name'),
            data_get($need, 'purpose'),
            data_get($need, 'note'),
        ]);

        if (data_get($scope, 'type') !== 'category' || ! $this->isProduceNeed($needText)) {
            return 0;
        }

        $pathText = $this->catalogScopePathText($scope, $scopeIndex);

        if (! preg_match('/(?:^|\s)ovochi-\d/u', $pathText)) {
            return 0;
        }

        $scopeText = Str::lower((string) data_get($scope, 'slug'));
        $roleBonus = (int) data_get($scope, 'total', 0) > 0
            && $this->containsAny($scopeText, ['salat', 'zelen'])
                ? 30
                : 0;

        return 100
            + $roleBonus
            + ((int) data_get($scope, 'depth', 0) * 5)
            + min((int) data_get($scope, 'total', 0), 25);
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array<string, array<string, mixed>>  $scopeIndex
     */
    private function catalogScopePathText(array $scope, array $scopeIndex): string
    {
        $parts = [];
        $currentScope = $scope;

        for ($depth = 0; $depth < 5; $depth++) {
            $parts[] = data_get($currentScope, 'slug');
            $parts[] = data_get($currentScope, 'label');
            $parentSlug = data_get($currentScope, 'parent_slug');

            if (! is_string($parentSlug) || $parentSlug === '' || ! isset($scopeIndex[$parentSlug])) {
                break;
            }

            $currentScope = $scopeIndex[$parentSlug];
        }

        return $this->text($parts);
    }

    /**
     * Prefer a frequently repeated positive identity token before exhausting long
     * conversational phrases. This is derived from the current need instead of a
     * product-name allowlist, so it also works for new catalog categories.
     *
     * @param  array<string, mixed>  $need
     * @return array<int, string>
     */
    private function orderedSearchQueries(array $need): array
    {
        $declaredQueries = collect([
            data_get($need, 'search_query'),
            data_get($need, 'product_name'),
            data_get($need, 'name'),
            ...data_get($need, 'search_queries', []),
        ])
            ->filter(fn (mixed $query): bool => is_string($query) && filled(trim($query)))
            ->map(fn (string $query): string => trim((string) preg_replace('/\s+/u', ' ', $query)))
            ->unique(fn (string $query): string => Str::lower($query))
            ->values();
        $rootStats = [];
        $position = 0;

        $identitySources = $declaredQueries->concat([
            (string) data_get($need, 'note', ''),
        ]);

        foreach ($identitySources as $query) {
            foreach ($this->positiveIdentityTokens($query) as $token) {
                $root = mb_substr($token, 0, 4);
                $rootStats[$root] ??= [
                    'count' => 0,
                    'first' => $position,
                    'tokens' => [],
                ];
                $rootStats[$root]['count']++;
                $rootStats[$root]['tokens'][$token] ??= ['count' => 0, 'first' => $position];
                $rootStats[$root]['tokens'][$token]['count']++;
                $position++;
            }
        }

        uasort($rootStats, fn (array $left, array $right): int => [
            -$left['count'],
            $left['first'],
        ] <=> [
            -$right['count'],
            $right['first'],
        ]);
        $lexicalQueries = collect($rootStats)
            ->filter(fn (array $stat): bool => $stat['count'] >= 2)
            ->map(function (array $stat): string {
                $tokens = $stat['tokens'];
                uasort($tokens, fn (array $left, array $right): int => [
                    -$left['count'],
                    $left['first'],
                ] <=> [
                    -$right['count'],
                    $right['first'],
                ]);

                return (string) array_key_first($tokens);
            });

        return $lexicalQueries
            ->concat($declaredQueries)
            ->unique(fn (string $query): string => Str::lower($query))
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $need */
    private function allowsBroadProduceRoleCandidate(array $need): bool
    {
        $needText = $this->text([
            data_get($need, 'name'),
            data_get($need, 'product_name'),
            data_get($need, 'purpose'),
            data_get($need, 'note'),
        ]);
        $latestAttempt = collect(data_get($need, 'attempts', []))->last();
        $latestQuery = is_array($latestAttempt) ? (string) data_get($latestAttempt, 'query', '') : '';

        return data_get($need, 'category', 'food') === 'food'
            && $this->containsAny($needText, ['овоч'])
            && $this->containsAny($needText, [
                'свіж', 'сирий', 'сира', 'грил', 'мангал', 'салат',
            ])
            && $this->containsAny(Str::lower($latestQuery), ['овоч']);
    }

    private function isProduceNeed(string $needText): bool
    {
        if ($this->containsAny($needText, [
            'чіпс', 'чипс', 'снек', 'крекер',
        ])) {
            return false;
        }

        return $this->containsAny($needText, [
            'овоч', 'гриб', 'печериц', 'шампіньйон', 'салат', 'зелень', 'фрукт', 'томат', 'помід', 'огір', 'перець',
            'кабач', 'цукіні', 'баклаж', 'капуст', 'моркв', 'буряк', 'картоп', 'цибул', 'часник',
        ]);
    }

    /** @param array<string, mixed> $need */
    private function isClearlySimpleNeed(array $need): bool
    {
        $needText = $this->text([
            data_get($need, 'name'),
            data_get($need, 'product_name'),
            data_get($need, 'purpose'),
        ]);

        if ($this->hasCompositeFoodMarkers($needText)) {
            return false;
        }

        return $this->isPlainWater($needText, data_get($need, 'category') === 'water')
            || $this->isProduceNeed($needText)
            || $this->isRawMeat($needText);
    }

    /** @param array<string, mixed> $need @param array<string, mixed> $candidate */
    private function isClearlySimpleFood(array $need, array $candidate): bool
    {
        if (! $this->isClearlySimpleNeed($need)) {
            return false;
        }

        $needText = $this->text([
            data_get($need, 'name'),
            data_get($need, 'product_name'),
            data_get($need, 'purpose'),
        ]);
        $candidateText = $this->text([
            data_get($candidate, 'name'),
            data_get($candidate, 'slug'),
        ]);

        if ($this->hasCompositeFoodMarkers($candidateText)) {
            return false;
        }

        if ($this->isPlainWater($needText, data_get($need, 'category') === 'water')) {
            return $this->isPlainWater($candidateText);
        }

        if ($this->isProduceNeed($needText)) {
            return $this->isProduceNeed($candidateText);
        }

        return $this->isRawMeat($needText) && $this->isRawMeat($candidateText);
    }

    private function isPlainWater(string $text, bool $waterCategory = false): bool
    {
        if (! $waterCategory && ! $this->containsAny($text, ['вода', 'water'])) {
            return false;
        }

        return ! $this->containsAny($text, [
            'зі смак', 'зi смак', 'смаком', 'ароматиз', 'лимон', 'апельс', 'ягід',
            'сік', 'juice', 'ізотон', 'енергет', 'вітамін', 'сироп', 'коктейл',
        ]);
    }

    private function isRawMeat(string $text): bool
    {
        return $this->containsAny($text, [
            'свин', 'pork', 'ялов', 'beef', 'теля', 'veal', 'курят', 'курин', 'chicken',
            'індич', 'turkey', 'баранин', 'lamb', 'мʼяс', "м'яс", 'meat',
        ]);
    }

    private function hasCompositeFoodMarkers(string $text): bool
    {
        return $this->containsAny($text, [
            'ковбас', 'сосиск', 'sausage', 'маринован', 'маринад', 'seasoned', 'приправлен',
            'панірован', 'breaded', 'coated', 'фарширован', 'начин', 'filled', 'фарш', 'minced',
            'копчен', 'smoked', 'варен', 'cooked', 'запечен', 'смажен', 'готов', 'напівфабрикат',
            'чіпс', 'чипс', 'chips', 'соус', 'sauce', 'суміш', 'mix', 'приправа', 'спеці',
        ]);
    }

    private function isLeafySaladNeed(string $needText): bool
    {
        return $this->containsAny($needText, [
            'салатні лист', 'листя салат', 'салат листов', 'салат-латук',
            'латук', 'айсберг', 'рукол', 'ромен', 'мангольд',
        ]);
    }

    private function isLeafySaladCandidate(string $candidateText): bool
    {
        return $this->containsAny($candidateText, [
            'айсберг', 'рукол', 'латук', 'ромен', 'шпинат', 'мангольд', 'корн',
            'мікрозелень', 'мікрогрін', 'паростк', 'щавель',
            'салат геній', 'салат листов', 'зелений салат', 'салат в упаков',
        ]);
    }

    /** @param array<string, mixed> $need @param array<string, mixed> $candidate */
    private function hasSufficientStock(array $need, array $candidate): bool
    {
        if (! in_array(Str::lower((string) data_get($need, 'unit')), ['кг', 'kg'], true)
            || ! is_numeric(data_get($candidate, 'stock'))) {
            return true;
        }

        $requiredKilograms = (float) data_get($need, 'quantity');
        $stock = (float) data_get($candidate, 'stock');

        if (data_get($candidate, 'weighted') === true) {
            return $stock + 0.0001 >= $requiredKilograms;
        }

        $ratio = Str::lower((string) data_get($candidate, 'display_ratio'));

        if (! preg_match('/([\d.,]+)\s*(кг|kg|гр|г|g)/u', $ratio, $matches)) {
            return true;
        }

        $mass = (float) str_replace(',', '.', $matches[1]);
        $kilogramsPerItem = in_array($matches[2], ['кг', 'kg'], true) ? $mass : $mass / 1000;

        return ($stock * $kilogramsPerItem) + 0.0001 >= $requiredKilograms;
    }

    /** @return array<int, string> */
    private function positiveIdentityTokens(string $query): array
    {
        $positivePhrase = preg_split('/\b(?:без|крім|without)\b/ui', Str::lower($query), 2)[0] ?? '';
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $positivePhrase, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return collect($tokens)
            ->filter(fn (string $token): bool => mb_strlen($token) >= 4)
            ->reject(fn (string $token): bool => $this->isIgnoredCatalogRoot(mb_substr($token, 0, 4)))
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function scopeRoots(string $text): array
    {
        $tokens = preg_split('/[^a-z0-9]+/', Str::ascii(Str::lower($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return collect($tokens)
            ->filter(fn (string $token): bool => strlen($token) >= 4 && ! ctype_digit($token))
            ->flatMap(function (string $token): array {
                $normalizedToken = str_replace(
                    ['shch', 'sh', 'ch', 'zh', 'kh', 'gh', 'ts', 'y', 'g'],
                    ['sc', 's', 'c', 'z', 'h', 'h', 'c', 'i', 'h'],
                    $token,
                );
                $root = substr($normalizedToken, 0, 5);

                return [$root];
            })
            ->reject(fn (string $root): bool => in_array($root, [
                'dlia', 'tova', 'prod', 'pozy', 'chas', 'paku', 'upak',
                'svizi', 'sviza', 'siri', 'sirii', 'fres',
            ], true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<string, int>
     */
    private function scopeRootWeights(array $values, int $weight): array
    {
        return collect($values)
            ->filter(fn (mixed $value): bool => is_scalar($value) && filled((string) $value))
            ->flatMap(fn (mixed $value): array => $this->scopeRoots((string) $value))
            ->countBy()
            ->map(fn (int $count): int => $count * $weight)
            ->all();
    }

    /** @param array<int, mixed> $values */
    private function text(array $values): string
    {
        return Str::lower(collect($values)
            ->flatten()
            ->filter(fn (mixed $value): bool => is_scalar($value))
            ->map(fn (mixed $value): string => (string) $value)
            ->implode(' '));
    }

    private function structuredText(mixed $value): string
    {
        if (! is_array($value)) {
            return is_scalar($value) ? Str::lower((string) $value) : '';
        }

        return Str::lower((string) json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /** @param array<int, string> $needles */
    private function containsAny(string $text, array $needles): bool
    {
        return Str::contains($text, $needles);
    }

    /** @param array<string, mixed> $need */
    private function sharesCatalogTerm(array $need, string $candidateText): bool
    {
        $needText = $this->text([
            data_get($need, 'product_name'),
            data_get($need, 'name'),
            data_get($need, 'search_query'),
            ...data_get($need, 'search_queries', []),
            ...collect(data_get($need, 'attempts', []))->pluck('query')->all(),
        ]);
        $needRoots = $this->catalogRoots($needText);

        if ($needRoots === []) {
            return true;
        }

        return array_intersect($needRoots, $this->catalogRoots($candidateText)) !== [];
    }

    /** @param array<string, mixed> $need */
    private function sharesNeedIdentityTerm(array $need, string $candidateText): bool
    {
        $identity = filled(data_get($need, 'product_name'))
            ? data_get($need, 'product_name')
            : data_get($need, 'name');
        $needRoots = $this->catalogRoots($this->text([$identity]));

        return $needRoots === []
            || array_intersect($needRoots, $this->catalogRoots($candidateText)) !== [];
    }

    /**
     * @param  array<string, mixed>  $need
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $eventContext
     */
    private function hasConclusiveSafetyEvidence(
        array $need,
        array $candidate,
        array $eventContext,
    ): bool {
        $needText = $this->text([data_get($need, 'name'), data_get($need, 'note')]);
        $constraintText = $this->text([
            data_get($eventContext, 'summary'),
            ...data_get($eventContext, 'restrictions', []),
        ]);
        $evidenceText = $this->text([
            data_get($candidate, 'name'),
            $this->structuredText(data_get($candidate, 'details')),
        ]);

        if ($this->containsAny($needText, ['безглютен'])) {
            return $this->containsAny($evidenceText, [
                'безглютен', 'gluten free',
            ]);
        }

        if ($this->containsAny($needText, ['без цукру'])) {
            return $this->containsAny($evidenceText, [
                'без цукру', 'нуль цукру', '0 цукру', 'цукри 0', 'цукрів 0',
                'sugar free', 'zero sugar', 'sugar 0',
            ]);
        }

        if ($this->containsAny($needText, ['арахіс', 'peanut'])) {
            return $this->containsAny($evidenceText, [
                'не містить арахіс', 'без арахіс', 'no peanut', 'peanut free',
            ]);
        }

        if ($this->containsAny($needText, ['соус'])) {
            $peanutConfirmed = ! $this->containsAny($constraintText, ['арахіс'])
                || $this->containsAny($evidenceText, [
                    'не містить арахіс', 'без арахіс', 'no peanut', 'peanut free',
                ]);
            $dairyConfirmed = ! $this->containsAny($constraintText, ['молочн', 'лактоз'])
                || $this->containsAny($evidenceText, [
                    'без молочн', 'без лактоз', 'веган', 'vegan', 'dairy free',
                ]);

            return $peanutConfirmed && $dairyConfirmed;
        }

        return false;
    }

    /** @return array<int, string> */
    private function catalogRoots(string $text): array
    {
        $normalizedText = str_replace('чіпс', 'чипс', Str::lower($text));
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $normalizedText, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return collect($tokens)
            ->filter(fn (string $token): bool => mb_strlen($token) >= 4)
            ->map(fn (string $token): string => mb_substr($token, 0, 4))
            ->reject(fn (string $root): bool => $this->isIgnoredCatalogRoot($root))
            ->unique()
            ->values()
            ->all();
    }

    private function isIgnoredCatalogRoot(string $root): bool
    {
        return collect([
            'альт', 'банк', 'безп', 'бути', 'варі', 'гото', 'грил', 'добр', 'зеле', 'кіло', 'літр',
            'манг', 'напі', 'окре', 'паке', 'пачк', 'пози', 'прод', 'свіж', 'сири', 'серт',
            'това', 'упак', 'харч', 'част', 'штук', 'якос',
        ])->contains(fn (string $ignoredRoot): bool => str_starts_with($root, $ignoredRoot));
    }
}
