---
paths:
  - 'app/{Data,Jobs,Services}/**'
  - 'app/{Data,Jobs,Services}/**/*Cart*.php'
  - 'app/{Data,Jobs,Services}/**/*.php'
---

# Data Jobs Services

## Resolve event evidence by message time
Full event synthesis receives sources grouped by upload_batch. Visible chat date/time outranks upload time; position is transfer order only. A reliably later explicit correction replaces the old value and must not be emitted as a warning or unresolved question; safety restrictions require attributable correction.

## Reject out-of-purpose image evidence
Image extraction accepts only chat evidence or products plausibly useful for planning the event. Unrelated chats, random images, and unrelated commercial products are irrelevant, get an empty message timeline, and are dismissed with a concise factual Ukrainian reason in light Goose Sho voice; short plausible event snippets must not be over-rejected.

## Stage explained MVP fallbacks with evidence grades
Prefer exact catalog matches, then general same-role substitutes that preserve category, use, preparation state, and known hard exclusions. Reject any product explicitly disclosing a forbidden allergen. After bounded detail checks, missing allergen/composition disclosure may be staged as unverified only with a visible question-mark package-check warning; never present it as proven safe.

## Scope food restrictions to intended consumers
Treat an allergy or dietary restriction as a hard SKU constraint only when the affected participant is an intended consumer, or when the source makes the requirement group-wide. First try a suitable safe variant. If none is available and the item can still serve other guests, keep it with a clear participant-specific warning that the affected person must avoid it; do not universalize one person's restriction across the cart.

## Distinguish incomplete evidence from a known conflict
Do not reject a catalog candidate merely because composition or allergen detail is absent after bounded inspection; stage it with a visible package-check warning when identity and role are proven and no forbidden property is disclosed. Treat products explicitly catalogued as non-alcoholic as non-alcoholic even when the technical declaration is up to 0.5%, unless the event explicitly requires exactly 0.0%. Keep known allergen, preparation-state, and physical-role conflicts strict; an LLM-proposed species change may be a visible same-role replacement only after exact searches are exhausted.

## Use gluten-free labels without proof-document checks
For the MVP, an explicit gluten-free catalog label is enough to match the product role. Do not add proof-document requirements to prompts, needs, or queries. Missing full composition may remain an unverified package warning; explicit conflicts still reject.

## Let the model propose broad visible replacements
Exhaust exact catalog queries first. If the exact need is unavailable, let the LLM order practical alternatives by purpose similarity and stop at the first viable result; PHP must not contain product or species substitution maps. A hard identity change such as meat species may be staged only as a visible same-role replacement, while explicit allergens and preparation-state conflicts remain hard rejections.

## Skip unrelated allergen checks for obvious simple foods
Treat clearly single-ingredient raw unseasoned meat, whole fresh produce, and plain water as safety_evidence=not_required for unrelated allergens unless catalog evidence explicitly discloses the allergen or may-contain cross-contact. Safety instructions and negated phrases such as "check marinade" or "not marinated" are not positive evidence that the product is composite. Processed, marinated, seasoned, or otherwise composite foods remain strict.

## Bound lexical retries and preserve explicit physical form
Prepare a short natural product-family or head-noun query among the first three declared aliases for both harness modes. In agentic selection, do not spend the tool budget on repeated inflections: after declared aliases allow at most two new text queries, then browse live catalog scopes or replacements. An explicitly required physical form such as boneless needs positive title/detail evidence; a same-species compatible form may be a visible role replacement after exact options are exhausted, but an incompatible closer-named cut must be rejected.
