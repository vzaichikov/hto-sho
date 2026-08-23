# Мангал на Дніпрі — восьмеро без плюс-одинів

## Purpose and checkpoints

This file is the human oracle for the full event-to-Silpo run–fix–run scenario. Re-read it:

1. before generating the screenshot fixtures;
2. before input wave one (T01–T05);
3. before input wave two (T06–T10);
4. before semantic state and plan evaluation;
5. before approving the staged Silpo cart;
6. before final Silpo cart read-back evaluation.

Do not hide catalog imperfections to fit an implementation result. Prefer an exact item, then stage the closest same-role product with a short explanation. A product that explicitly contains a forbidden allergen is rejected; missing allergen/composition disclosure after bounded checks is staged with a visible question-mark warning for package review.

## Event

- Title: `Мангал на Дніпрі — восьмеро без плюс-одинів`
- Description: `Недільний пікнік біля води з мангалом: мʼясо, овочі та безпечні спільні закупи.`
- People count: exactly `8`
- Budget ceiling: `6000 UAH`
- Alcohol planned: `false`
- Participants: Роман, Іра, Оля, Маша, Леся, Тарас, Богдан, Катя

## Exact text submissions

Each entry is submitted separately and only once.

### T01

`23 серпня, 09:00, Роман: На недільний мангал нас рівно 8 без плюс-одинів: Роман, Іра, Оля, Маша, Леся, Тарас, Богдан і Катя. Усі підтвердилися; якщо хтось передумає — напише сам.`

Attachments, in this order: `S03-lesia-quoted-correction.png`, `S01-poll-eight.png`.

### T02

`23 серпня, 09:05, Роман: я свинину люблю на шашлик`

Attachment: `S04-masha-allergy-ambiguous.png`.

### T03

`23 серпня, 09:06, Іра: а я хочу стейки з телятіни`

Attachment: `S06-olia-tentative-brings.png`.

### T04

`23 серпня, 09:10, Катя: Я буду, але сиру цибулю не люблю — запечена ок. Богдан теж буде; казав, що через лактозу не їсть молочних соусів.`

Attachment: `S08-taras-old-coal.png`.

### T05

`23 серпня, 09:16, Роман: По речах поки так: Тарас ніби бере вугілля й розпал, Оля, може, хумус та лимонад, Катя, якщо встигне, лід. Це ще не фінальні обіцянки, не викреслюйте з закупів.`

Attachment: `S10-katia-maybe-ice.png`.

### T06

`25 серпня, 18:31, Оля: Я точно буду. Я вегетаріанка. Хумус 800 г беру сама — запечатаний і без арахісу чи маркування «може містити арахіс»; лимонад не беру. Овочі на гриль купіть на всіх.`

Attachment: `S07-olia-final-brings.png`.

### T07

`25 серпня, 18:35, Маша: Я буду. У мене сильна алергія саме на арахіс: нічого з арахісом і з маркуванням «може містити арахіс». Соуси та снеки перевіряйте за складом.`

Attachment: `S05-masha-forward-overlap.png`.

### T08

`25 серпня, 18:38, Леся: Дедлайн закрила, я точно буду. У мене целіакія: потрібне лише сертифіковане безглютенове, окремо від звичайного хліба.`

Attachment: `S02-lesia-old-decline.png`.

### T09

`25 серпня, 18:42, Богдан: Я буду; через непереносимість лактози без молочних соусів. Вугілля — дві пачки по 2,5 кг — і розпал беру точно я. Тарас везе мангал та 8 шампурів. Катя бере 2 пакети льоду, 8 багаторазових тарілок і стаканів.`

Attachment: `S09-taras-cancels-coal.png`.

### T10

`25 серпня, 18:50, Роман: Фінально: алкоголю не купуємо, усі за кермом. Купуємо 12 л негазованої води й 4 л лимонаду без цукру. З їжі — свинина на шашлик, стейки з телятини, овочі гриль; без арахісових соусів, молочних соусів, майонезу й готових маринадів. Бюджет на спільні покупки — до 6000 грн.`

Attachments, in this order: `S11-unrelated-work-chat.png`, `S12-peanut-satay-product.png`.

## Screenshot fixture oracle

All screenshots are deterministic fictional messenger/product fixtures. Dates, times, authors, quotation/forward markers, crops, and allergen text must remain readable.

| File | Visible evidence | Expected interpretation |
|---|---|---|
| `S01-poll-eight.png` | Poll has 8 votes, but only Роман, Іра, Оля, Маша, Катя are visible | Headcount evidence only; never invent three anonymous participants or an aggregate pseudo-participant |
| `S02-lesia-old-decline.png` | 22 Aug, Леся says she cannot come | Older decline, superseded by the visible 25 Aug correction even though this file is uploaded later |
| `S03-lesia-quoted-correction.png` | 25 Aug, Леся quotes the old decline then writes `Та вже розгребла 🙂 Я точно буду` | Current direct confirmation; quoted content is historical context |
| `S04-masha-allergy-ambiguous.png` | Катя says Маша seems allergic to either nuts or peanuts; Роман will ask | Third-party ambiguity, not a confirmed diagnosis |
| `S05-masha-forward-overlap.png` | A distinct forwarded/cropped overlap of S04 | Deduplicate semantically; never override the later direct statement from Маша |
| `S06-olia-tentative-brings.png` | Оля may bring hummus and lemonade | Tentative only; neither item is safe to exclude yet |
| `S07-olia-final-brings.png` | Оля definitely brings 800 g verified peanut-safe hummus and will not bring lemonade | Supersedes tentative commitment; exclude hummus from shopping and retain lemonade |
| `S08-taras-old-coal.png` | Тарас commits to charcoal and fire starter | Old commitment, later cancelled |
| `S09-taras-cancels-coal.png` | Тарас cancels; Богдан tentatively offers to take over | Supersedes Тарас; final T09 text confirms Богдан |
| `S10-katia-maybe-ice.png` | Катя might bring ice | Tentative; final T09 text confirms two bags |
| `S11-unrelated-work-chat.png` | Release/PR work chat only | `irrelevant`, empty timeline, concise dismissal, absent from synthesis |
| `S12-peanut-satay-product.png` | Product card for satay sauce with `арахіс 35%` | Relevant `product_image` evidence but forbidden by Маша's hard constraint |

## Wave checkpoints

### Interim after T01–T05

- The event has exactly the eight roster names, with no anonymous or aggregate participant.
- A question about Маша's exact allergy is acceptable.
- Оля's hummus/lemonade, charcoal/fire starter, and ice remain tentative and must not be excluded from shopping merely because of tentative promises.
- Visible chronology makes Леся's 25 Aug correction current even before the old screenshot is uploaded.
- Generate and record the interim state revision and interim plan revision.

### After T06–T10

- Newly accepted evidence makes both the interim derived state and interim shopping plan stale.
- Re-analysis produces a final current event-state revision and a final current shopping-plan revision.
- Final state and plan must use the same final revision before any cart run starts.

## Final semantic oracle

- Exactly eight participants and no others: Роман, Іра, Оля, Маша, Леся, Тарас, Богдан, Катя.
- Every participant is confirmed.
- Роман prefers pork for shashlik.
- Іра prefers veal steaks; generic beef is not the same preference.
- Оля is vegetarian and brings exactly 800 g of sealed, peanut-safe hummus; she does not bring lemonade.
- Маша has a severe peanut allergy, including products labelled `може містити арахіс`.
- Леся has coeliac disease and requires certified gluten-free food kept separate from ordinary bread.
- Богдан has lactose intolerance and brings two 2.5 kg charcoal packs plus fire starter.
- Тарас brings the grill and eight skewers; he does not bring charcoal/fire starter.
- Катя dislikes raw onion but accepts cooked onion; she brings two bags of ice and reusable plates/cups for eight.
- No alcohol.
- No duplicate facts, participants, restrictions, commitments, or questions from overlapping screenshots.
- No aggregate pseudo-participant such as `8 учасників…`.
- No blocking questions and no false chronology warnings in the final state.
- S11 contributes no timeline or synthesized fact.
- S12 may produce a safety warning but cannot enter the plan/cart.
- Provenance points to the current direct evidence and records superseded evidence where appropriate.

## Final shopping-plan oracle

The plan serves exactly eight and stays within the `6000 UAH` ceiling.

| Need | Expected quantity/safety |
|---|---|
| Pork suitable for shashlik | approximately 1.6–2.4 kg, raw and without ready marinade |
| Veal suitable for steaks | approximately 1.2–1.8 kg; never silently replace with generic beef |
| Grilling vegetables/shared vegetable main | approximately 2–3 kg and substantial enough for Оля |
| Certified gluten-free bread or crispbread | 1–2 packs; certification/detail must be established |
| Still water | exactly 12 L |
| Sugar-free lemonade | exactly 4 L |
| Napkins/trash bags | optional, event-appropriate |

The plan excludes alcohol, peanut products/sauces, dairy sauces, mayonnaise, ready marinades, hummus, charcoal, fire starter, grill, skewers, ice, and reusable tableware.

Allowed substitutions, evaluated by role rather than a hardcoded product-name pair:

- another suitable raw pork cut for shashlik;
- another explicitly veal cut suitable for steaks; if the refreshed branch exposes no veal after bounded text and category checks, the MVP may stage a raw steak-suitable beef cut only as an explicit non-exact species fallback with a visible warning that it is not veal;
- certified gluten-free bread or crispbread when available, otherwise the closest packaged gluten-free bread-role product with an explicit review warning;
- another fresh salad vegetable for an unavailable fresh salad vegetable;
- another raw grilling vegetable for an unavailable raw grilling vegetable;
- another still-water brand;
- another sugar-free non-alcoholic soft drink when the requested lemonade is unavailable;
- another product with the same practical event role for supplies.

Known hard conflicts remain forbidden: presenting generic beef as veal or silently satisfying Іра's exact preference with it, a product explicitly containing or possibly containing peanut, an explicitly dairy sauce, ordinary gluten bread for the coeliac guest, alcohol, a ready marinade, or a prepared dish in place of a raw grilling product. A beef steak fallback is acceptable only under the exhausted-branch rule above and remains visibly non-exact. Missing catalog evidence is not presented as safety; it is staged as `unverified` with `❓` and requires package review.

## Cart mutation and acceptance oracle

1. Revalidate the existing Silpo cart route and delivery slot, clear the existing cart once as the explicitly authorized test setup, then snapshot the empty baseline before product matching.
2. Discover tools through the user's active OAuth-backed Silpo MCP connection.
3. Use assisted matching and stop in `waiting_for_confirmation` after SKU audit. Auto mode must use the same final human gate.
4. Show actual SKU names, absolute quantities, estimated total, and warnings in the UI before mutation.
5. Re-read this file and manually evaluate every staged SKU, its evidence grade, role-substitution explanation, and package-check warning.
6. Confirm exactly once. Replays, a stale plan, changed route, or changed slot must not duplicate or blindly write.
7. Use absolute managed quantities. Do not clear the cart again after the empty baseline snapshot, and preserve any line added outside the managed run from that point onward.
8. Read the cart back through MCP and compare it with both the before-snapshot and staged targets.
9. After the one authorized setup clear, never clear the cart again. Do not change the address, branch, delivery type, checkout, payment, promotions, bonuses, or certificates. If the chosen slot expires before matching, the owner may explicitly confirm the displayed nearest available slot on the same route.
10. Leave the evaluated cart filled.

Final acceptance requires:

- current state, plan, and synchronized cart-run records use the same final revision;
- every required need has an exact or explained same-role staged item, quantities are sufficient for eight, and the total is under `6000 UAH`;
- every missing allergen/composition disclosure is visibly marked `unverified` with `❓`, while every known forbidden allergen is absent;
- final run is `synced`, not `partial`;
- read-back proves exact managed quantities, no duplicates or stock overruns, no managed validation errors, and preservation of unrelated lines;
- temporary local application session is destroyed while QA events and sanitized artifacts remain for traceability.
