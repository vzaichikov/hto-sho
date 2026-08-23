# Silpo catalog search evidence

This reference records branch-scoped search behavior observed through the live OAuth-backed MCP catalog on 2026-08-23. Treat product availability as temporary; reuse the search patterns, not the observed inventory.

## Repeated patterns

- Conversational intent phrases such as meat “for shashlik”, veal “steaks”, or “vegetables for grill” may return no results or mostly prepared products.
- A retail cut or catalog noun can surface raw products that the event-intent phrase misses. The result set may still mix raw, marinated, smoked, and prepared forms, so filter candidates rather than trusting rank.
- Negative safety wording is a poor discovery query. A sauce query framed as “without peanut” returned nothing, while positive families such as tomato sauce or ketchup returned candidates that then require ingredient inspection.
- Inflection, singular/plural, and word order materially change results. Generate independent queries instead of mechanically appending one word to the previous failure.
- Fresh-produce nouns can rank preserved or prepared products above fresh stock. Require raw/fresh form evidence and accept that the selected branch may genuinely lack a fresh item.
- Descriptive dietary identities such as gluten-free bread work well, but nearby baked goods and mixes still appear. Inspect attributes when certification or cross-contact matters.
- Natural supply phrases such as garbage bags are high precision; very broad nouns such as napkins mix household, wet, cosmetic, and table products.
- Water identity plus “still” is high precision. Beverage flavor plus a negative sugar constraint can be low recall; do not silently replace the requested beverage family with cola merely because both are sugar-free.
- The live category tree can recover products that text search misses. On 2026-08-23 the branch exposed specific leaf categories for zucchini/courgette, pork, veal, vegetables, sauces, gluten-free products, drinks, and household supplies. Match current need terms to live slugs; do not copy these dated slugs into application allowlists.
- Curated product sets are often broad campaigns or brand collections. They are a secondary discovery channel, not proof that each product fills the need; retain lexical/role checks inside a matching set.
- A matching leaf name is insufficient when it belongs to the wrong branch. Use the live parent chain to keep produce under fresh fruit/vegetables and to reject household, equipment, fish/meat, prepared-food, and other cross-class scopes.
- Branch stock is a hard quantity constraint. If whole-package rounding or a weighted requirement exceeds current stock, discard that candidate instead of lowering the cart quantity.
- The same available raw produce SKU can legitimately cover several compatible vegetable roles after exact and category attempts are exhausted. Reuse it only when the aggregate quantity remains available, then send one grouped absolute quantity to the cart.

## Category examples from the live probes

These are query-shape examples, not an allowlist of product names. Product names, brands, SKUs, stock, and ranking remain live catalog data.

| Human need | Weak or misleading query shape | Independent alternatives worth trying | Evidence required after search |
|---|---|---|---|
| Meat for shashlik | the finished dish name alone | species + raw retail cut; retail cut + species; species head noun | species, raw/unmarinated form, a cut suitable for grilling |
| Veal steaks | “steak” alone, which can widen to beef or prepared products | veal + steak-suitable cut; cut + veal; veal raw meat | veal rather than generic beef, suitable cut, raw form |
| Vegetables for grilling | the menu phrase as one product | decompose into two or three complementary fresh vegetables, then query each noun independently | fresh/raw form; reject fritters, salads, preserves, marinades, and ready dishes |
| Fresh salad vegetables or leaves | color or adjective alone | produce noun; common lemma; reordered fresh-produce form | fresh/raw identity; do not match by a shared adjective such as “green” |
| Allergen-safe sauce | a negative phrase such as “without peanut” | positive sauce family; tomato-based family; ketchup or another same-role family | composition plus allergen/cross-contact evidence; name alone is insufficient |
| Sugar-free lemonade | a long phrase containing volume, flavor, and exclusions | beverage identity + sugar-free property; reordered identity; same beverage-family lemma | explicit zero-sugar evidence; do not change the beverage family to cola or water |
| Certified gluten-free bread | a generic “bread” search | gluten-free bread identity; certification phrase + bread; same-role crispbread only when allowed by the plan | explicit gluten-free/certification evidence and safe product type |
| Still water | a broad “water” query | still-water identity; water + still property; reordered form | still/non-carbonated identity and pack-adjusted total volume |
| Event supplies | an overly broad household noun | functional noun plus intended use, then the common catalog lemma | intended function; reject cosmetic, toy, book, and cleaning cross-category hits |

Across these examples, early alternatives change the language used to find the exact need. The final bounded alternatives may change the literal product while preserving its role, category, preparation state, and all known hard exclusions. Do not encode fixed replacement pairs.

## Query construction

For each need, prepare two to six short queries in this order:

1. The agreed product identity without quantity or packaging.
2. A common catalog lemma or reordered form.
3. A same-need retail form, raw cut, or positive product family when the original phrase describes cooking intent.
4. One or two same-role alternatives when exact variants fail: another fresh salad vegetable, raw grilling vegetable, soft drink with the requested property, or product serving the same function.
5. If lexical attempts still fail, score the live category tree and product-set metadata against the whole need (name, note, and prepared alternatives), browse the most specific matching scope, and run every returned product through the same hard filters.

Search-query uniqueness is scoped to a single need. Decomposed needs may legitimately share a broad fallback such as a product class or intended use. After category browsing, reject cross-class candidates deterministically: category membership is not enough when the product is equipment instead of food, processed meat instead of produce, or a seasoning instead of a substantial fresh-vegetable role.

Do not include forbidden substitutions in the query list. Search one need per MCP call, retain every attempted query, and stop at the application limit.

## Candidate evaluation

- Search results are candidates, not evidence of suitability.
- Category membership can justify broad same-role discovery, but never overrides species, raw/prepared form, alcohol, or known allergen rules. Thematic-set membership does not by itself justify a role match.
- Rank vetted candidates by exact role first, whole-package fit second, and total cost third. A cheaper candidate is not better when its sale step creates a large unnecessary overage.
- Preserve explicit species and product form.
- Reject prepared or marinated forms when the plan requires raw food.
- Use product details for composite foods, allergen exclusions, certified dietary claims, and ambiguous labels.
- Reject a product when its title or details explicitly disclose a forbidden allergen or other hard conflict.
- When bounded detail checks expose no allergen/composition information, grade the selection `unverified`, add it to the reviewable staged cart, and show a question-mark package-check warning. Do not call it safe.
- Do not infer absence from one query. Report an external catalog blocker only when exact and same-role query forms produce no selectable candidate.
- Keep plan quantities authoritative across LLM decomposition. When one approved broad need becomes several search needs, their normalized total must equal the original plan amount before package rounding.
