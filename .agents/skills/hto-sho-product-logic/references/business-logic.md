# Хто Шо? Business Logic Reference

## Product Promise

A small group should be able to provide the chat material they already have and receive one understandable, current food plan without manually polling everyone again. The product reduces coordination work; it does not conceal uncertainty or take purchasing control away from the organizer.

## Inputs

An event may receive sources over time:

- pasted messages, notes, or chat exports;
- screenshots from Telegram, Viber, or any other messenger;
- organizer context such as event type, expected headcount, budget, or cooking constraints.

Sources are cumulative evidence. Identical content should not be counted twice. Preserve enough provenance to explain where an important conclusion came from and to reconsider it when later evidence conflicts.

Screenshots may contain OCR mistakes, cropped context, reactions, quoted messages, forwarded content, or repeated messages across images. Treat extracted text as evidence that still needs synthesis, not as an authoritative form submission.

## Current Event State

The normalized state should be structured and model-independent. Exact storage keys may follow existing code, but the domain needs these concepts when the sources support them:

- participants and attendance status;
- food preferences;
- allergies and dietary or medical restrictions;
- drinks and non-food needs;
- items or dishes each participant will bring;
- menu decisions and event constraints;
- budget and currency;
- source-backed warnings, contradictions, and unresolved questions.

Keep three meanings distinct:

1. **Fact:** explicitly stated or clearly visible in a source.
2. **Inference:** a reasonable interpretation that may help planning but needs transparent confidence or review.
3. **Unknown:** missing or contradictory information that could materially change the plan.

## Conflict Resolution

- A later explicit correction normally supersedes an earlier statement: `Я все ж буду` replaces `Я не прийду` when chronology and speaker identity are clear.
- A reaction, joke, ambiguous emoji, or third-party claim should not silently override a participant's explicit statement.
- When chronology, speaker identity, or intent is unclear, retain the conflict and ask or warn instead of selecting the convenient answer.
- Never soften or discard an allergy because another message suggests that most of the group wants the item.

## Shopping Plan

Generate the shopping plan from the current normalized event state, not independently from each source. The plan should be understandable before any MCP calls and should include:

- a food or menu rationale appropriate to the event;
- generic purchasable needs with quantity, unit, and category;
- the participant count or other assumption behind each meaningful quantity;
- exclusions or adjustments caused by allergies, restrictions, preferences, or items brought by participants;
- budget impact when budget data and reliable prices are available;
- warnings and unresolved decisions that could change the list.

Avoid false precision. If attendance or serving size is unknown, expose the assumption or use a range instead of inventing a confidently exact quantity.

Participant contributions reduce the shared purchase need. They do not disappear from the event state, because the organizer still needs to know who is responsible for them.

## Version and Staleness Semantics

Derived data must identify the exact event-state revision used to create it:

- New accepted evidence produces a new state revision after synthesis.
- A shopping plan is current only when its source revision equals the current event-state revision.
- A Silpo cart synchronization is current only when it represents the current shopping-plan revision.
- New or changed evidence makes older derived output stale. Keep it for traceability if useful, but do not present it as current or synchronize it as though nothing changed.

This rule prevents an old cart from remaining apparently valid after someone reports an allergy, cancels attendance, changes the menu, or volunteers to bring an item.

## Human Review

The user should be able to see the interpreted state, warnings, shopping plan, and estimated result before causing a cart mutation. A cart-sync action authorizes creating or updating the draft cart for that current plan; it does not authorize checkout, payment, substitutions outside the constraints, or future automatic cart changes after the event state changes.

## Failure Behavior

- Keep source-processing failures attached to the affected source when possible.
- Keep an earlier valid state available but visibly stale while new evidence is unresolved.
- A partial analysis may be useful if it clearly identifies what failed or remains unknown.
- MCP/catalog failure must not corrupt the normalized event state or pretend the cart is synchronized.
- Retrying analysis or MCP operations must not duplicate sources, state changes, or cart items.
