<?php

namespace Tests\Unit;

use App\CartProductEvidence;
use App\Services\CartCandidateSuitability;
use PHPUnit\Framework\TestCase;

class CartCandidateSuitabilityTest extends TestCase
{
    public function test_it_rejects_ready_marinade_when_the_event_forbids_it(): void
    {
        $this->assertFalse((new CartCandidateSuitability)->allows(
            ['name' => 'свинина на шашлик', 'note' => 'Окремо під шашлик'],
            ['name' => 'Свинячий шашлик маринований', 'slug' => 'svyniachyi-shashlyk-marynovanyi'],
            ['summary' => 'Без арахісових соусів, майонезу й готових маринадів.'],
            [],
        ));
    }

    public function test_it_accepts_a_raw_pork_cut_for_shashlik(): void
    {
        $this->assertTrue((new CartCandidateSuitability)->allows(
            ['name' => 'свинина на шашлик', 'note' => 'Окремо під шашлик'],
            ['name' => 'Шия свиняча охолоджена', 'slug' => 'shyia-svyniacha'],
            ['summary' => 'Без готових маринадів.'],
            [],
        ));
    }

    public function test_it_does_not_require_a_known_cut_name_for_raw_pork_from_the_matching_category(): void
    {
        $suitability = new CartCandidateSuitability;
        $need = [
            'name' => 'свинина на шашлик',
            'note' => 'Сира, без готового маринаду',
            'quantity' => 2.2,
            'unit' => 'кг',
        ];
        $catalogScope = [
            'type' => 'category',
            'slug' => 'svynyna-4413',
            'matched' => true,
        ];

        $this->assertTrue($suitability->allows($need, [
            'name' => 'Свиняче яблучко фермерське',
            'weighted' => true,
            'stock' => 2.8,
            'catalog_scope' => $catalogScope,
        ], [], []));
        $this->assertTrue($suitability->allows($need, [
            'name' => 'Свинячий кострець фермерський',
            'weighted' => true,
            'stock' => 4.4,
            'catalog_scope' => $catalogScope,
        ], [], []));
        $this->assertFalse($suitability->allows($need, [
            'name' => 'Шашлик зі свинини маринований',
            'weighted' => true,
            'stock' => 4.4,
            'catalog_scope' => $catalogScope,
        ], [], []));
    }

    public function test_potato_chips_are_not_misclassified_as_fresh_produce(): void
    {
        $suitability = new CartCandidateSuitability;
        $need = [
            'name' => 'Картопляні чіпси',
            'category' => 'food',
            'quantity' => 1,
            'unit' => 'пачка',
            'search_queries' => ['картопляні чіпси', 'чипси картопляні'],
        ];
        $candidate = [
            'name' => "Чипси Lay's зі смаком солі",
            'slug' => 'chypsy-lays-zi-smakom-soli',
            'available' => true,
            'stock' => 20,
            'weighted' => false,
            'display_ratio' => '120 г',
        ];

        $this->assertTrue($suitability->allows($need, $candidate, [], []));
        $this->assertSame(
            CartProductEvidence::MATCH_EXACT,
            $suitability->evidence($need, $candidate, [], [])['match'],
        );
    }

    public function test_it_rejects_processed_pork_and_offers_a_raw_cut_query(): void
    {
        $suitability = new CartCandidateSuitability;
        $need = [
            'name' => 'свинина на шашлик',
            'note' => 'Без готових маринадів',
            'attempts' => [
                ['query' => 'свинина на шашлик'],
                ['query' => 'свинина для шашлику'],
            ],
            'search_queries' => ['свинина на шашлик', 'свинина для шашлику', 'сирий свинячий відруб'],
        ];

        $this->assertFalse($suitability->allows($need, ['name' => 'Ковбаса по-домашньому'], [], []));
        $this->assertFalse($suitability->allows($need, ['name' => 'Шашлик із свинини напівфабрикат'], [], []));
        $this->assertSame('свинина', $suitability->nextSearchQuery($need));
    }

    public function test_it_tries_a_frequent_positive_catalog_lemma_before_exhausting_long_queries(): void
    {
        $suitability = new CartCandidateSuitability;
        $need = [
            'name' => 'свинина на шашлик',
            'search_query' => 'свинина на шашлик',
            'search_queries' => [
                'свинина на шашлик',
                'шашлик зі свинини',
                'свинина для шашлику',
                'мʼясо свинини для гриля',
            ],
            'attempts' => [['query' => 'свинина на шашлик']],
        ];

        $this->assertSame('свинина', $suitability->nextSearchQuery($need));
    }

    public function test_semantic_meat_replacement_stays_model_driven_while_php_keeps_stock_checks(): void
    {
        $suitability = new CartCandidateSuitability;
        $need = [
            'name' => 'стейки з телятини',
            'note' => 'Сирий стейковий відруб',
            'search_queries' => ['стейки з телятини', 'сирий телячий відруб для стейка'],
            'attempts' => [['query' => 'стейки з телятини']],
        ];

        $this->assertTrue($suitability->allows($need, ['name' => 'Стейк яловичий Рібай'], [], []));
        $this->assertTrue($suitability->allows($need, ['name' => 'Вирізка теляча охолоджена'], [], []));
        $this->assertTrue($suitability->allows($need, ['name' => 'Телячий биток охолоджений'], [], []));
        $this->assertSame('стейки', $suitability->nextSearchQuery($need));
        $need['attempts'][] = ['query' => 'стейки'];
        $this->assertSame('телятини', $suitability->nextSearchQuery($need));
        $this->assertFalse($suitability->allows([
            ...$need,
            'quantity' => 1.5,
            'unit' => 'кг',
        ], [
            'name' => 'Телячий биток охолоджений',
            'weighted' => true,
            'stock' => 0.5,
        ], [], []));
        $this->assertTrue($suitability->allows([
            ...$need,
            'quantity' => 1.5,
            'unit' => 'кг',
        ], [
            'name' => 'Телячий биток охолоджений',
            'weighted' => true,
            'stock' => 1.5,
        ], [], []));

        $exhaustedNeed = [
            ...$need,
            'attempts' => collect(range(1, 6))
                ->map(fn (int $attempt): array => ['query' => "телятина {$attempt}", 'total_found' => 0])
                ->all(),
            'browse_attempts' => [[
                'type' => 'category',
                'slug' => 'steiky-4457',
                'total_found' => 0,
            ]],
        ];
        $beefFallback = [
            'name' => 'Яловичий стейк Ribeye Wet Aged охолоджений',
            'catalog_scope' => [
                'type' => 'category',
                'slug' => 'yalovychyna-ta-teliatyna-4414',
                'matched' => true,
            ],
        ];

        $this->assertTrue($suitability->allows($exhaustedNeed, $beefFallback, [], []));
        $fallbackEvidence = $suitability->evidence($exhaustedNeed, $beefFallback, [], [], null, true);
        $this->assertTrue($fallbackEvidence['selectable']);
        $this->assertSame(CartProductEvidence::MATCH_SAME_ROLE, $fallbackEvidence['match']);
        $this->assertStringContainsString('Заміна', (string) $fallbackEvidence['review_note']);
    }

    public function test_high_risk_bread_and_sauce_needs_require_details(): void
    {
        $suitability = new CartCandidateSuitability;
        $context = ['summary' => 'Маша має алергію на арахіс; Леся має целіакію.'];

        $this->assertTrue($suitability->requiresInspection(
            ['name' => 'безглютеновий хліб', 'note' => 'Для Лесі'],
            $context,
        ));
        $this->assertTrue($suitability->requiresInspection(
            ['name' => 'безпечний соус', 'note' => 'Без арахісу'],
            $context,
        ));
        $this->assertFalse($suitability->requiresInspection(
            ['name' => 'негазована вода', 'note' => '12 л'],
            $context,
        ));
    }

    public function test_fresh_or_grill_vegetables_reject_preserved_and_prepared_forms(): void
    {
        $suitability = new CartCandidateSuitability;

        $this->assertFalse($suitability->allows(
            ['name' => 'огірки', 'note' => 'Додаткова свіжа овочева позиція'],
            ['name' => 'Огірки солоні'],
            [],
            [],
        ));
        $this->assertFalse($suitability->allows(
            ['name' => 'перець для гриля', 'note' => 'На мангал'],
            ['name' => 'Перець солодкий стерилізований'],
            [],
            [],
        ));
        $this->assertFalse($suitability->allows(
            ['name' => 'перець болгарський для гриля', 'note' => 'Сирий овоч на мангал'],
            ['name' => 'Лечо болгарське'],
            [],
            [],
        ));
        $this->assertFalse($suitability->allows(
            ['name' => 'кабачки', 'note' => 'Сира позиція для мангалу'],
            ['name' => 'Оладки з кабачків в упаковці'],
            [],
            [],
        ));
        $this->assertFalse($suitability->allows(
            ['name' => 'баклажани', 'note' => 'Сира позиція для мангалу'],
            ['name' => 'Баклажани по-домашньому'],
            [],
            [],
        ));
        $this->assertFalse($suitability->allows(
            ['name' => 'баклажани', 'note' => 'Сира позиція для мангалу'],
            ['name' => 'Баклажани з томатами та часником'],
            [],
            [],
        ));
        $this->assertTrue($suitability->allows(
            ['name' => 'перець для гриля', 'note' => 'На мангал'],
            ['name' => 'Перець червоний'],
            [],
            [],
        ));
        $this->assertFalse($suitability->allows(
            ['name' => 'перець солодкий для гриля', 'note' => 'Сирий овоч на мангал'],
            ['name' => 'Перець чилі зелений'],
            [],
            [],
        ));
        $this->assertFalse($suitability->allows(
            ['name' => 'перець солодкий для гриля', 'note' => 'Сирий овоч на мангал'],
            ['name' => 'Перець Халапеньйо зелений нарізаний'],
            [],
            [],
        ));
        $this->assertFalse($suitability->allows(
            ['name' => 'перець солодкий для гриля', 'note' => 'Сирий овоч на мангал'],
            ['name' => 'Перець духмяний мелений'],
            [],
            [],
        ));
        $this->assertFalse($suitability->allows(
            ['name' => 'печериці для гриля', 'note' => 'Сирі гриби для мангалу'],
            [
                'name' => 'Чипси яблучні натуральні',
                'catalog_scope' => ['type' => 'category', 'matched' => true],
            ],
            [],
            [],
        ));
        $this->assertFalse($suitability->allows(
            ['name' => 'печериці', 'note' => 'Сирі гриби до овочів на мангал'],
            [
                'name' => 'Сьомга шматок охолоджений',
                'catalog_scope' => [
                    'type' => 'category',
                    'slug' => 'svizha-ryba-4431',
                    'matched' => true,
                ],
            ],
            [],
            [],
        ));
        $this->assertFalse($suitability->allows(
            ['name' => 'кабачки для гриля', 'note' => 'Сирий овоч для мангалу'],
            [
                'name' => 'Асорті овочеве Квартет',
                'catalog_scope' => ['type' => 'category', 'matched' => true],
            ],
            [],
            [],
        ));
        $this->assertFalse($suitability->allows(
            ['name' => 'баклажани для гриля', 'note' => 'Сирий овоч для мангалу'],
            ['name' => 'Баклажани гострі зі східними прянощами'],
            [],
            [],
        ));
        $this->assertFalse($suitability->allows(
            ['name' => 'помідори', 'note' => 'Овочевий гарнір'],
            ['name' => 'Томат зелений солоний'],
            [],
            [],
        ));
        $this->assertFalse($suitability->allows(
            ['name' => 'помідори', 'note' => 'Свіжий овочевий гарнір'],
            ['name' => 'Томати різані шматочками'],
            [],
            [],
        ));
        $this->assertFalse($suitability->allows(
            ['name' => 'помідори', 'note' => 'Для салату'],
            ['name' => "Салат «Олів'є Літнє»"],
            [],
            [],
        ));
    }

    public function test_grill_pepper_identity_prefers_a_suitable_pepper_over_chilli_and_carrot(): void
    {
        $suitability = new CartCandidateSuitability;
        $need = [
            'name' => 'перець для гриля',
            'product_name' => 'перець',
            'purpose' => 'для гриля',
            'search_queries' => ['перець', 'овочі'],
        ];
        $sweetPepper = ['name' => 'Перець червоний солодкий'];

        $this->assertTrue($suitability->allows($need, $sweetPepper, [], []));
        $this->assertFalse($suitability->allows($need, ['name' => 'Перець чилі зелений'], [], []));
        $this->assertTrue($suitability->isExactIdentityCandidate($need, $sweetPepper));
        $this->assertFalse($suitability->isExactIdentityCandidate($need, ['name' => 'Морква молода']));
        $this->assertTrue($suitability->hasExactIdentityCandidate($need, [
            ['name' => 'Морква молода'],
            $sweetPepper,
        ]));
    }

    public function test_catalog_noise_must_match_a_meaningful_need_or_synonym_root(): void
    {
        $suitability = new CartCandidateSuitability;
        $need = [
            'name' => 'кабачки',
            'note' => 'Сира позиція для мангалу',
            'search_queries' => ['кабачки', 'кабачок свіжий', 'цукіні'],
            'attempts' => [['query' => 'цукіні']],
        ];

        $this->assertFalse($suitability->allows(
            $need,
            ['name' => 'Молоко згущене кокосове на тростинному цукрі'],
            [],
            [],
        ));
        $this->assertFalse($suitability->allows(
            [
                'name' => 'кабачки для гриля',
                'category' => 'food',
                'quantity' => 1.2,
                'note' => 'Сирий овоч для мангалу',
                'search_queries' => ['кабачки', 'цукіні', 'овочі'],
            ],
            ['name' => 'Кабаноси свинні в/к', 'slug' => 'kabanosy-svynni'],
            [],
            [],
        ));
        $this->assertFalse($suitability->allows(
            [
                'name' => 'печериці для гриля',
                'category' => 'food',
                'quantity' => 0.8,
                'note' => 'Сирі гриби для мангалу',
            ],
            [
                'name' => 'Картридж газовий для гриля',
                'catalog_scope' => [
                    'type' => 'category',
                    'slug' => 'mangaly-i-reshitky-dlia-gryliu',
                    'matched' => true,
                ],
            ],
            [],
            [],
        ));
        $this->assertTrue($suitability->allows(
            $need,
            ['name' => 'Цукіні зелені вагові'],
            [],
            [],
        ));
        $this->assertFalse($suitability->allows(
            [
                'name' => 'печериці для гриля',
                'category' => 'food',
                'search_queries' => ['печериці', 'гриби'],
            ],
            ['name' => 'Конструктор LEGO Minecraft Бій в печері 30705'],
            [],
            [],
        ));
    }

    public function test_it_accepts_an_explained_same_role_fresh_vegetable_replacement(): void
    {
        $suitability = new CartCandidateSuitability;
        $need = [
            'name' => 'огірки',
            'note' => 'Свіжі овочі до спільного столу',
            'quantity' => 1,
            'unit' => 'кг',
            'search_queries' => ['огірки', 'огірок свіжий', 'свіжий салатний овоч', 'томати'],
            'attempts' => [['query' => 'томати']],
        ];
        $candidate = ['name' => 'Томати рожеві', 'slug' => 'tomaty-rozhevi'];

        $this->assertTrue($suitability->allows($need, $candidate, [], []));
        $this->assertSame(
            CartProductEvidence::MATCH_SAME_ROLE,
            $suitability->evidence($need, $candidate, [], [])['match'],
        );
        $this->assertStringContainsString('Заміна для «огірки»', (string) $suitability->evidence(
            $need,
            $candidate,
            [],
            [],
        )['review_note']);
        $this->assertFalse($suitability->allows($need, [
            'name' => 'Часник',
            'slug' => 'chasnyk',
            'catalog_scope' => [
                'type' => 'category',
                'slug' => 'ovochi',
                'matched' => true,
            ],
        ], [], []));
        $this->assertFalse($suitability->allows($need, [
            'name' => 'Перець Палермо',
            'slug' => 'perets-palermo',
            'weighted' => false,
            'display_ratio' => 'шт',
            'catalog_scope' => [
                'type' => 'category',
                'slug' => 'ovochi',
                'matched' => true,
            ],
        ], [], []));
    }

    public function test_a_broad_produce_role_query_can_surface_an_unlisted_fresh_replacement(): void
    {
        $suitability = new CartCandidateSuitability;
        $need = [
            'name' => 'кабачки для гриля',
            'note' => 'Сирий овоч для мангалу',
            'search_query' => 'кабачки',
            'search_queries' => ['кабачки', 'цукіні', 'овочі кабачок'],
            'attempts' => [['query' => 'кабачки']],
        ];

        $this->assertSame('овочі', $suitability->nextSearchQuery($need));

        $need['attempts'][] = ['query' => 'овочі'];
        $candidate = ['name' => 'Томат коктейльний чорний', 'slug' => 'tomat-kokteilnyi'];

        $this->assertTrue($suitability->allows($need, $candidate, [], []));
        $this->assertSame(
            CartProductEvidence::MATCH_SAME_ROLE,
            $suitability->evidence($need, $candidate, [], [])['match'],
        );
    }

    public function test_catalog_scope_selection_prefers_the_most_specific_matching_category_then_a_matching_set(): void
    {
        $suitability = new CartCandidateSuitability;
        $scopes = [
            'categories' => [
                ['type' => 'category', 'slug' => 'frukty-ovochi-4788', 'depth' => 0, 'parent_slug' => null],
                ['type' => 'category', 'slug' => 'ovochi-4808', 'depth' => 1, 'parent_slug' => 'frukty-ovochi-4788'],
                ['type' => 'category', 'slug' => 'kabachky-tsukini-4811', 'depth' => 2, 'parent_slug' => 'ovochi-4808'],
                ['type' => 'category', 'slug' => 'mangaly-i-reshitky-dlia-gryliu-4664', 'depth' => 2],
            ],
            'sets' => [
                ['type' => 'set', 'slug' => 'dlia-lehkoi-vecheri', 'label' => 'Для легкої вечері'],
            ],
        ];

        $this->assertSame('kabachky-tsukini-4811', data_get($suitability->nextCatalogScope([
            'name' => 'кабачки для гриля',
            'note' => 'Сирий овоч для мангалу',
            'search_queries' => ['цукіні'],
        ], $scopes), 'slug'));
        $this->assertSame('ovochi-4808', data_get($suitability->nextCatalogScope([
            'name' => 'кабачки для гриля',
            'note' => 'Сирий овоч для мангалу',
            'search_queries' => ['цукіні'],
            'browse_attempts' => [[
                'type' => 'category',
                'slug' => 'kabachky-tsukini-4811',
                'total_found' => 0,
            ]],
        ], $scopes), 'slug'));
        $this->assertSame('lisovi-gryby-4849', data_get($suitability->nextCatalogScope([
            'name' => 'печериці для гриля',
            'note' => 'Сирі гриби для мангалу',
            'browse_attempts' => [
                ['type' => 'category', 'slug' => 'pecherytsi-4850', 'total_found' => 0],
                ['type' => 'category', 'slug' => 'gryby-4846', 'total_found' => 0],
            ],
        ], [
            'categories' => [
                ['type' => 'category', 'slug' => 'frukty-ovochi-4788', 'depth' => 0, 'parent_slug' => null],
                ['type' => 'category', 'slug' => 'gryby-4846', 'depth' => 1, 'parent_slug' => 'frukty-ovochi-4788'],
                ['type' => 'category', 'slug' => 'pecherytsi-4850', 'depth' => 2, 'parent_slug' => 'gryby-4846'],
                ['type' => 'category', 'slug' => 'ekzotychni-gryby-4848', 'depth' => 2, 'parent_slug' => 'gryby-4846'],
                ['type' => 'category', 'slug' => 'lisovi-gryby-4849', 'depth' => 2, 'parent_slug' => 'gryby-4846'],
                ['type' => 'category', 'slug' => 'susheni-gryby-4851', 'depth' => 2, 'parent_slug' => 'gryby-4846'],
                ['type' => 'category', 'slug' => 'ovochi-4808', 'depth' => 1, 'parent_slug' => 'frukty-ovochi-4788'],
            ],
            'sets' => [],
        ]), 'slug'));
        $this->assertSame('ovochi-4808', data_get($suitability->nextCatalogScope([
            'name' => 'печериці для гриля',
            'note' => 'Частина овочів для гриля; сирі гриби для мангалу',
            'browse_attempts' => [
                ['type' => 'category', 'slug' => 'pecherytsi-4850', 'total_found' => 0],
                ['type' => 'category', 'slug' => 'gryby-4846', 'total_found' => 0],
                ['type' => 'category', 'slug' => 'ekzotychni-gryby-4848', 'total_found' => 0],
                ['type' => 'category', 'slug' => 'lisovi-gryby-4849', 'total_found' => 0],
                ['type' => 'category', 'slug' => 'mini-ovochi-4827', 'total_found' => 0],
            ],
        ], [
            'categories' => [
                ['type' => 'category', 'slug' => 'frukty-ovochi-4788', 'depth' => 0, 'parent_slug' => null],
                ['type' => 'category', 'slug' => 'gryby-4846', 'depth' => 1, 'parent_slug' => 'frukty-ovochi-4788'],
                ['type' => 'category', 'slug' => 'pecherytsi-4850', 'depth' => 2, 'parent_slug' => 'gryby-4846'],
                ['type' => 'category', 'slug' => 'ekzotychni-gryby-4848', 'depth' => 2, 'parent_slug' => 'gryby-4846'],
                ['type' => 'category', 'slug' => 'lisovi-gryby-4849', 'depth' => 2, 'parent_slug' => 'gryby-4846'],
                ['type' => 'category', 'slug' => 'susheni-gryby-4851', 'depth' => 2, 'parent_slug' => 'gryby-4846'],
                ['type' => 'category', 'slug' => 'mini-ovochi-4827', 'depth' => 2, 'parent_slug' => 'ovochi-4808'],
                ['type' => 'category', 'slug' => 'ovochi-4808', 'depth' => 1, 'parent_slug' => 'frukty-ovochi-4788'],
                ['type' => 'category', 'slug' => 'svizha-ryba-4431', 'depth' => 1, 'parent_slug' => 'ryba-4428'],
            ],
            'sets' => [],
        ]), 'slug'));
        $this->assertSame('set', data_get($suitability->nextCatalogScope([
            'name' => 'легка вечеря',
            'note' => 'Тематична добірка для вечері',
        ], $scopes), 'type'));
        $this->assertSame('pomidory-4825', data_get($suitability->nextCatalogScope([
            'name' => 'салатні листки',
            'category' => 'food',
            'note' => 'Свіжа позиція до салату',
            'search_queries' => ['листя салату', 'салат зелений'],
            'browse_attempts' => [
                ['type' => 'category', 'slug' => 'zelen-i-salaty-4829', 'total_found' => 0],
                ['type' => 'category', 'slug' => 'salaty-4831', 'total_found' => 0],
            ],
        ], [
            'categories' => [
                ['type' => 'category', 'slug' => 'frukty-ovochi-4788', 'depth' => 0, 'parent_slug' => null],
                ['type' => 'category', 'slug' => 'zelen-i-salaty-4829', 'depth' => 1, 'parent_slug' => 'frukty-ovochi-4788'],
                ['type' => 'category', 'slug' => 'salaty-4831', 'depth' => 2, 'parent_slug' => 'zelen-i-salaty-4829'],
                ['type' => 'category', 'slug' => 'ovochi-4808', 'depth' => 1, 'parent_slug' => 'frukty-ovochi-4788'],
                ['type' => 'category', 'slug' => 'pomidory-4825', 'depth' => 2, 'parent_slug' => 'ovochi-4808', 'total' => 20],
                ['type' => 'category', 'slug' => 'kapusta-4813', 'depth' => 2, 'parent_slug' => 'ovochi-4808', 'total' => 4],
                ['type' => 'category', 'slug' => 'salaty-ta-zakusky-4777', 'depth' => 1, 'parent_slug' => 'gotovi-stravy-4761'],
                ['type' => 'category', 'slug' => 'salaty-4779', 'depth' => 2, 'parent_slug' => 'salaty-ta-zakusky-4777', 'total' => 40],
            ],
            'sets' => [],
        ]), 'slug'));
        $this->assertSame('pomidory-4825', data_get($suitability->nextCatalogScope([
            'name' => 'листя салату',
            'category' => 'food',
            'note' => 'Свіжа зелень для гарніру',
            'browse_attempts' => [
                ['type' => 'category', 'slug' => 'zelen-i-salaty-4829', 'total_found' => 0],
                ['type' => 'category', 'slug' => 'salaty-4831', 'total_found' => 0],
                ['type' => 'category', 'slug' => 'zelen-miks-4835', 'total_found' => 0],
            ],
        ], [
            'categories' => [
                ['type' => 'category', 'slug' => 'frukty-ovochi-4788', 'depth' => 0, 'parent_slug' => null],
                ['type' => 'category', 'slug' => 'zelen-i-salaty-4829', 'depth' => 1, 'parent_slug' => 'frukty-ovochi-4788'],
                ['type' => 'category', 'slug' => 'salaty-4831', 'depth' => 2, 'parent_slug' => 'zelen-i-salaty-4829'],
                ['type' => 'category', 'slug' => 'zelen-miks-4835', 'depth' => 2, 'parent_slug' => 'zelen-i-salaty-4829'],
                ['type' => 'category', 'slug' => 'ovochi-4808', 'depth' => 1, 'parent_slug' => 'frukty-ovochi-4788'],
                ['type' => 'category', 'slug' => 'pomidory-4825', 'depth' => 2, 'parent_slug' => 'ovochi-4808', 'total' => 20],
                ['type' => 'category', 'slug' => 'zelenyi-chai-5127', 'depth' => 2, 'parent_slug' => 'chai-5126', 'total' => 40],
                ['type' => 'category', 'slug' => 'vypichka-z-lystkovogo-tista-5149', 'depth' => 2, 'parent_slug' => 'vypichka-5138', 'total' => 30],
            ],
            'sets' => [],
        ]), 'slug'));
        $this->assertSame('svynyna-4413', data_get($suitability->nextCatalogScope([
            'name' => 'свинина на шашлик',
            'note' => 'Сире мʼясо без маринаду',
            'search_queries' => ['свиняча шийка', "м'ясо свинини для мангалу"],
        ], [
            'categories' => [
                [
                    'type' => 'category',
                    'slug' => 'mango-4799',
                    'depth' => 2,
                ],
                [
                    'type' => 'category',
                    'slug' => 'mangaly-i-reshitky-dlia-gryliu-4664',
                    'depth' => 2,
                ],
                [
                    'type' => 'category',
                    'slug' => 'shyika-kopchena-4749',
                    'depth' => 2,
                ],
                [
                    'type' => 'category',
                    'slug' => 'svynyna-4413',
                    'depth' => 1,
                ],
            ],
            'sets' => [],
        ]), 'slug'));
        $this->assertSame('pecherytsi-4850', data_get($suitability->nextCatalogScope([
            'name' => 'печериці для гриля',
            'category' => 'food',
            'note' => 'Сирі гриби для мангалу',
            'search_queries' => ['печериці', 'гриби', 'шампіньйони'],
        ], [
            'categories' => [
                ['type' => 'category', 'slug' => 'mangaly-i-reshitky-dlia-gryliu-4664', 'depth' => 2],
                ['type' => 'category', 'slug' => 'frukty-ovochi-4788', 'depth' => 0, 'parent_slug' => null],
                ['type' => 'category', 'slug' => 'gryby-4846', 'depth' => 1, 'parent_slug' => 'frukty-ovochi-4788'],
                ['type' => 'category', 'slug' => 'pecherytsi-4850', 'depth' => 2, 'parent_slug' => 'gryby-4846'],
            ],
            'sets' => [],
        ]), 'slug'));
    }

    public function test_salad_leaves_do_not_match_a_product_only_because_it_is_green(): void
    {
        $suitability = new CartCandidateSuitability;
        $need = [
            'name' => 'салатні листки',
            'search_queries' => ['салатні листки', 'листя салату', 'салат зелений'],
        ];

        $this->assertFalse($suitability->allows($need, ['name' => 'Цибуля зелена'], [], []));
        $this->assertFalse($suitability->allows($need, ['name' => 'Листя виноградне для долми'], [], []));
        $this->assertFalse($suitability->allows($need, ['name' => 'Салат Бурячок'], [], []));
        $this->assertTrue($suitability->allows($need, ['name' => 'Салат Айсберг'], [], []));
        $this->assertFalse($suitability->allows($need, ['name' => 'Салат з броколі'], [], []));
        $this->assertFalse($suitability->allows([
            ...$need,
            'name' => 'салат-латук',
        ], ['name' => "Салат «Олів'є» літній в упаковці"], [], []));
    }

    public function test_salad_leaves_can_fall_back_to_a_raw_fresh_produce_role_after_catalog_exhaustion(): void
    {
        $suitability = new CartCandidateSuitability;
        $need = [
            'name' => 'салатні листки',
            'category' => 'food',
            'search_queries' => ['салатні листки', 'листя салату'],
            'attempts' => collect(['салатні', 'листки', 'салатні листки', 'листя салату'])
                ->map(fn (string $query): array => ['query' => $query, 'total_found' => 0])
                ->all(),
            'browse_attempts' => [
                ['type' => 'category', 'slug' => 'zelen-i-salaty-4829', 'total_found' => 0],
                ['type' => 'category', 'slug' => 'salaty-4831', 'total_found' => 0],
            ],
        ];
        $candidate = [
            'name' => 'Капуста молода',
            'catalog_scope' => [
                'type' => 'category',
                'slug' => 'kapusta-4813',
                'matched' => true,
            ],
        ];

        $this->assertTrue($suitability->allows($need, $candidate, [], []));
        $this->assertFalse($suitability->allows($need, [
            ...$candidate,
            'name' => 'Капуста квашена',
        ], [], []));
        $this->assertTrue($suitability->allowsProductReuseForNeed($need, $candidate));
        $this->assertFalse($suitability->allowsProductReuseForNeed([
            ...$need,
            'attempts' => [],
        ], $candidate));
    }

    public function test_exact_fresh_produce_can_reuse_a_staged_sku_after_declared_queries_are_exhausted(): void
    {
        $suitability = new CartCandidateSuitability;
        $need = [
            'name' => 'помідори',
            'category' => 'food',
            'search_query' => 'помідори',
            'search_queries' => ['помідори', 'томати', 'помідор свіжий', 'томати свіжі'],
            'attempts' => [
                ['query' => 'помідори'],
                ['query' => 'томати'],
                ['query' => 'помідор свіжий'],
                ['query' => 'томати свіжі'],
            ],
            'browse_attempts' => [],
        ];
        $candidate = [
            'name' => 'Томат сливка жовтий',
            'slug' => 'tomat-slyvka-zhovtyi-514241',
            'catalog_scope' => [
                'type' => 'category',
                'slug' => 'pomidory-4825',
                'matched' => true,
            ],
        ];

        $this->assertTrue($suitability->allowsProductReuseForNeed($need, $candidate));
        $this->assertFalse($suitability->allowsProductReuseForNeed([
            ...$need,
            'attempts' => array_slice($need['attempts'], 0, 3),
        ], $candidate));
        $this->assertTrue($suitability->allowsProductReuseForNeed([
            ...$need,
            'search_queries' => [...$need['search_queries'], 'помідори салатні'],
            'attempts' => collect(range(1, 6))->map(
                fn (int $attempt): array => ['query' => "запит {$attempt}"],
            )->all(),
            'browse_attempts' => [['type' => 'category', 'slug' => 'ovochi-4808']],
        ], $candidate));
    }

    public function test_teliachi_word_form_can_accept_a_model_selected_beef_role_fallback(): void
    {
        $suitability = new CartCandidateSuitability;
        $need = [
            'name' => 'телячі стейки',
            'category' => 'food',
            'quantity' => 1.5,
            'unit' => 'кг',
            'attempts' => [],
            'browse_attempts' => [],
        ];
        $beef = [
            'name' => 'Яловичий стейк Ribeye охолоджений',
            'slug' => 'yalovychyi-steik-ribeye',
            'stock' => 3,
            'available' => true,
            'weighted' => true,
        ];

        $this->assertTrue($suitability->allows($need, $beef, [], []));

        $exhaustedNeed = [
            ...$need,
            'attempts' => collect(range(1, 6))->map(fn (int $attempt): array => ['query' => "телятина {$attempt}"])->all(),
            'browse_attempts' => [['type' => 'category', 'slug' => 'yalovychyna-ta-teliatyna-4414']],
        ];
        $evidence = $suitability->evidence($exhaustedNeed, $beef, [], [], null, true);

        $this->assertTrue($evidence['selectable']);
        $this->assertSame(CartProductEvidence::MATCH_SAME_ROLE, $evidence['match']);
        $this->assertStringContainsString('Заміна', (string) $evidence['review_note']);
    }

    public function test_fresh_greens_reject_dried_seasoning_leaves(): void
    {
        $suitability = new CartCandidateSuitability;
        $need = ['name' => 'зелень', 'note' => 'Для легкого гарніру'];

        $this->assertFalse($suitability->allows($need, ['name' => 'Лист лавровий'], [], []));
        $this->assertTrue($suitability->allows($need, ['name' => 'Кінза пучок'], [], []));
    }

    public function test_leafy_salad_role_accepts_fresh_microgreens_and_sprouts_from_the_live_category(): void
    {
        $suitability = new CartCandidateSuitability;
        $need = [
            'name' => 'салатні листки',
            'category' => 'food',
        ];

        $this->assertTrue($suitability->allows($need, ['name' => 'Мікрозелень гороху зрізана'], [], []));
        $this->assertTrue($suitability->allows($need, ['name' => 'Паростки мікрогрін мікс салатний'], [], []));
        $this->assertFalse($suitability->allows($need, ['name' => 'Салат Олівʼє готовий'], [], []));
    }

    public function test_a_negative_sauce_reference_in_a_produce_note_does_not_trigger_allergen_inspection(): void
    {
        $suitability = new CartCandidateSuitability;

        $this->assertFalse($suitability->requiresInspection([
            'name' => 'салатні листки',
            'note' => 'Брати окремо від соусів і готових салатів.',
        ], [
            'summary' => 'Сильна алергія на арахіс.',
        ]));
        $this->assertTrue($suitability->requiresInspection([
            'name' => 'соус до шашлику',
            'note' => '',
        ], [
            'summary' => 'Сильна алергія на арахіс.',
        ]));
    }

    public function test_table_napkins_reject_cosmetic_tissues(): void
    {
        $suitability = new CartCandidateSuitability;
        $need = [
            'name' => 'одноразові серветки',
            'category' => 'supplies',
            'note' => 'Паперові серветки до спільної їжі',
        ];

        $this->assertFalse($suitability->allows($need, ['name' => 'Серветки косметичні Zewa'], [], []));
        $this->assertFalse($suitability->allows($need, ['name' => "Серветки Fino універсальні м'які"], [], []));
        $this->assertFalse($suitability->allows($need, ['name' => 'Серветки для інтимної гігієни'], [], []));
        $this->assertFalse($suitability->allows($need, ['name' => 'Хустинки носові'], [], []));
        $this->assertTrue($suitability->allows($need, ['name' => 'Серветки паперові столові'], [], []));
    }

    public function test_soft_drink_need_rejects_solid_food_and_alcoholic_results(): void
    {
        $suitability = new CartCandidateSuitability;
        $need = ['name' => 'лимонад без цукру', 'category' => 'soft_drinks'];

        $this->assertFalse($suitability->allows($need, [
            'name' => 'Шоколад з лимоном без цукру',
            'display_ratio' => '70г',
        ], [], []));
        $this->assertFalse($suitability->allows($need, [
            'name' => 'Напій винний Zero безалкогольний',
            'display_ratio' => '0,75л',
        ], [], []));
        $this->assertTrue($suitability->allows($need, [
            'name' => 'Напій лимонний нуль цукру безалкогольний',
            'display_ratio' => '1,25л',
        ], [], []));
    }

    public function test_soft_drink_need_accepts_an_explicitly_non_alcoholic_version_of_its_product_family(): void
    {
        $suitability = new CartCandidateSuitability;
        $need = [
            'name' => 'безалкогольне пиво для водія',
            'category' => 'soft_drinks',
            'quantity' => 1,
            'unit' => 'л',
            'search_queries' => ['безалкогольне пиво', 'пиво 0.0'],
        ];

        $this->assertTrue($suitability->allows($need, [
            'name' => 'Пиво Hike Zero 0.0 світле безалкогольне',
            'display_ratio' => '0,5л',
            'available' => true,
            'stock' => 214,
        ], [], []));
        $this->assertFalse($suitability->allows($need, [
            'name' => 'Пиво світле алкогольне',
            'display_ratio' => '0,5л',
            'available' => true,
            'stock' => 214,
        ], [], []));
    }

    public function test_high_risk_sauce_rejects_known_allergen_but_stages_missing_evidence_with_a_question_mark(): void
    {
        $suitability = new CartCandidateSuitability;
        $need = [
            'name' => 'соус без арахісу і без молочних інгредієнтів',
            'search_queries' => ['соус', 'кетчуп'],
        ];
        $context = ['summary' => 'Без арахісу та молочних соусів.'];

        $unknownCandidate = [
            'name' => 'Кетчуп до шашлику',
            'details' => ['attributes' => ['Вуглеводи' => 22]],
        ];

        $this->assertTrue($suitability->allows($need, $unknownCandidate, $context, []));
        $unknownEvidence = $suitability->evidence($need, $unknownCandidate, $context, []);
        $this->assertSame(CartProductEvidence::SAFETY_UNVERIFIED, $unknownEvidence['safety']);
        $this->assertStringContainsString('❓', (string) $unknownEvidence['review_note']);
        $this->assertTrue($suitability->allows($need, [
            'name' => 'Кетчуп до шашлику',
            'details' => ['description' => 'Склад: томати, сіль. Алергени: не містить арахісу. Веган.'],
        ], $context, []));
        $verifiedEvidence = $suitability->evidence($need, [
            'name' => 'Кетчуп до шашлику',
            'details' => ['description' => 'Склад: томати, сіль. Алергени: не містить арахісу. Веган.'],
        ], $context, []);
        $this->assertSame(CartProductEvidence::SAFETY_VERIFIED, $verifiedEvidence['safety']);
        $this->assertFalse($suitability->allows($need, [
            'name' => 'Кетчуп до шашлику',
            'details' => ['description' => 'Склад: томати. Може містити арахіс. Веган.'],
        ], $context, []));
    }

    public function test_a_packaged_food_with_an_explicit_peanut_constraint_requires_safety_evidence(): void
    {
        $suitability = new CartCandidateSuitability;
        $need = [
            'name' => 'картопляні чипси',
            'note' => 'Без арахісу та маркування «може містити арахіс».',
        ];
        $context = ['summary' => 'Сильна алергія на арахіс.'];
        $unknownCandidate = [
            'name' => 'Чипси картопляні з сіллю',
            'details' => ['attributes' => ['Маса' => '120 г']],
        ];

        $this->assertTrue($suitability->requiresInspection($need, $context));
        $this->assertSame(
            CartProductEvidence::SAFETY_UNVERIFIED,
            $suitability->evidence($need, $unknownCandidate, $context, [])['safety'],
        );
        $this->assertSame(
            CartProductEvidence::SAFETY_VERIFIED,
            $suitability->evidence($need, [
                'name' => 'Чипси картопляні з сіллю',
                'details' => ['description' => 'Алергени: не містить арахісу.'],
            ], $context, [])['safety'],
        );
        $this->assertFalse($suitability->allows($need, [
            'name' => 'Чипси картопляні з сіллю',
            'details' => ['description' => 'Може містити арахіс.'],
        ], $context, []));
    }

    public function test_sugar_free_drink_prefers_explicit_evidence_but_can_stage_an_unverified_fallback(): void
    {
        $suitability = new CartCandidateSuitability;
        $need = ['name' => 'лимонад без цукру', 'search_queries' => ['лимонад без цукру', 'лимонад']];

        $this->assertTrue($suitability->requiresInspection($need, []));
        $unknownCandidate = [
            'name' => 'Напій Лимонада апельсин-персик',
            'details' => ['attributes' => ['Вуглеводи' => 10]],
        ];

        $this->assertTrue($suitability->allows($need, $unknownCandidate, [], []));
        $this->assertSame(
            CartProductEvidence::SAFETY_UNVERIFIED,
            $suitability->evidence($need, $unknownCandidate, [], [])['safety'],
        );
        $this->assertTrue($suitability->allows($need, [
            'name' => 'Лимонад Zero без цукру',
            'details' => ['description' => 'Цукри 0 г.'],
        ], [], []));
    }

    public function test_simple_raw_foods_ignore_an_absurd_unrelated_allergen_warning(): void
    {
        $suitability = new CartCandidateSuitability;
        $rawPork = $suitability->evidence(
            ['name' => 'raw pork', 'note' => 'no peanuts'],
            [
                'name' => 'Chilled pork chunks',
                'details' => ['attributes' => ['Country' => 'Ukraine']],
            ],
            [],
            [],
            CartProductEvidence::SAFETY_UNVERIFIED,
        );
        $wholePepper = $suitability->evidence(
            ['name' => 'перець для гриля', 'note' => 'без арахісу'],
            ['name' => 'Перець червоний'],
            [],
            [],
            CartProductEvidence::SAFETY_UNVERIFIED,
        );
        $plainWater = $suitability->evidence(
            ['name' => 'вода негазована', 'category' => 'water', 'note' => 'без арахісу'],
            ['name' => 'Вода питна негазована'],
            [],
            [],
            CartProductEvidence::SAFETY_UNVERIFIED,
        );

        foreach ([$rawPork, $wholePepper, $plainWater] as $evidence) {
            $this->assertTrue($evidence['selectable']);
            $this->assertSame(CartProductEvidence::SAFETY_NOT_REQUIRED, $evidence['safety']);
            $this->assertNull($evidence['review_note']);
        }

        $this->assertFalse($suitability->requiresInspection(
            ['name' => 'свинина сира', 'note' => 'без арахісу'],
            ['summary' => 'Сильна алергія на арахіс'],
        ));
    }

    public function test_composite_foods_keep_missing_allergen_disclosure_visible(): void
    {
        $suitability = new CartCandidateSuitability;
        $pairs = [
            [['name' => 'ковбаса'], ['name' => 'Ковбаса домашня']],
            [['name' => 'свинина маринована'], ['name' => 'Свинина маринована']],
            [['name' => 'картопляні чіпси'], ['name' => 'Чипси картопляні']],
            [['name' => 'соус до гриля'], ['name' => 'Соус томатний']],
        ];

        foreach ($pairs as [$need, $candidate]) {
            $evidence = $suitability->evidence(
                $need,
                $candidate,
                [],
                [],
                CartProductEvidence::SAFETY_UNVERIFIED,
            );

            $this->assertSame(CartProductEvidence::SAFETY_UNVERIFIED, $evidence['safety']);
            $this->assertStringContainsString('❓', (string) $evidence['review_note']);
        }
    }
}
