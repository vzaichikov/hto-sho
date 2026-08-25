# Silpo MCP Harness Contract

## Harness-facing Description

> Use the OAuth-authenticated Silpo MCP server to read the current guest profile, find products for an approved Хто Шо? shopping plan, and create or update a reviewable cart. Keep dietary restrictions and allergies as hard constraints, preserve the event-plan revision, and never checkout or pay for an order.

This description is for an AI harness, not a claim that every target MCP capability is already wired into the Laravel application.

For the discovered tool names, annotations, required inputs, and the exact core cart/product input shapes, read [silpo-mcp-tools.md](silpo-mcp-tools.md). It is a dated snapshot; live `tools/list` remains authoritative.

## Role of Each Component

- **OpenAI or Ollama model:** extracts and synthesizes event meaning, reports uncertainty, and produces the provider-independent shopping plan.
- **Хто Шо? application:** owns authorization, source provenance, event and plan revisions, user review, orchestration, validation, persistence, and retry/idempotency rules.
- **Silpo MCP:** supplies authenticated Silpo profile, catalog/product, availability or pricing when exposed, and cart capabilities.

The model must not treat its own product names, prices, or availability as catalog truth. Those values come from current MCP results.

## Connection and Discovery

- The repository's default endpoint is `https://mcp.silpo.ua/mcp` over streamable HTTP.
- Authentication is OAuth and belongs to the current Хто Шо? user. Never place access or refresh tokens in prompts, model-visible state, logs, or documentation.
- The current repository uses `silpo_get_my_profile` to resolve the authenticated guest. Discover the live MCP tool list and schemas before implementing product search or cart calls; do not invent tool names or arguments from the dated snapshot.
- Treat live MCP schemas as the transport authority and this reference as the business-intent authority. If they conflict, stop and report the incompatibility rather than fabricating a call.

## Tool-use Stages

1. **Authenticate and identify:** establish the OAuth context and, when needed, read the guest profile.
2. **Confirm and reset:** before route or catalog work, obtain explicit reset consent, read the current cart only for an encrypted event-scoped backup, clear every product, and immediately prove the same cart is empty.
3. **Choose a fresh route:** require a new place, store, and time choice; write it once and verify exact readback while the cart remains empty.
4. **Find candidates:** use text search first, then live category and relevant thematic-set browsing, current price/availability data, and event constraints to propose specific products.
5. **Validate mapping:** ensure every selected item maps back to a generic plan need and violates no allergy or restriction. Keep missing or ambiguous matches visible.
6. **Mutate products only after final SKU review:** write the approved absolute quantities for the current plan revision.
7. **Verify:** immediately read the resulting cart and confirm identifiers, quantities, failures, validations, and totals.

Catalog searches and profile reads are non-cart discovery. The early reset confirmation authorizes only backup plus a full product clear. Adding matched products remains a separate external mutation requiring the final SKU-review action.

## Selection Rules

- Match by suitability first, then availability, quantity/pack fit, budget, and preference. Do not optimize price by violating a safety constraint.
- Do not infer that a product is safe from its title alone when allergy or dietary suitability depends on ingredients or metadata that MCP did not provide. After bounded product-detail checks, a product with no disclosed conflict may be staged as `unverified` with a visible package-check warning; an explicitly disclosed forbidden allergen remains rejected.
- Keep generic plan items separate from chosen Silpo SKUs so the plan remains understandable and can be rematched later.
- Prefer an exact match, then an explained same-role substitute that preserves category, intended use, and hard known exclusions. Surface the substitution for review instead of encoding fixed product or brand pairs.
- Category and thematic-set browsing are fallback discovery channels. A matched category may widen to the same role; a thematic collection still needs a product-level identity match. Neither can weaken hard exclusions.
- Deterministic product evidence, aggregate stock checks, and plan-authoritative quantity math own final coverage. A free-form audit may reopen a need only when a real deterministic gap remains; prose alone must not invalidate an allowed, visibly explained same-role or `unverified` staged item.
- Leave a need unresolved only when the catalog returns no candidate for either the exact need or reasonable same-role alternatives, or every candidate has a known hard conflict.

## Mutation and Retry Rules

- Bind every sync attempt to the authenticated user, event, and current plan revision.
- The discovered server exposes the authenticated user's current cart, not a separate create-cart operation. A new run therefore reinitializes that cart: back up its complete pre-clear payload encrypted, clear all products after explicit consent, and verify an empty readback before route work.
- Prefer an MCP-supported absolute quantity update that converges on the intended cart. The discovered add/update tool is annotated non-idempotent, so re-read the cart before any retry and never blindly repeat a timed-out call.
- Group repeated staged uses of the same SKU into one aggregate absolute quantity, verify aggregate stock first, and keep `addQuantity=false` semantics.
- Never preserve or merge pre-existing lines into a new run. If any product appears after the verified reset, invalidate the run; a replayed reset token must not clear newly changed non-empty contents.
- If the final product write fails, retain the approved staged set and the safe Silpo error detail. A retry may write that exact set only after revalidating the plan, reset, route, and absence of foreign products.
- Stop if the event or plan revision changes during synchronization. The new state needs a new review and sync.
- Persist cart identifiers and the synchronized plan revision only after the MCP response confirms success.
- Record per-item failures without marking the whole cart current when required items were not synchronized.
- Never continue from cart creation to checkout, order placement, or payment.

## Minimum Context Supplied to the Harness

Provide only the data needed for product matching:

- event and plan revision identifiers;
- generic shopping items with quantities and units;
- relevant restrictions, allergies, preferences, budget, and allowed substitutions;
- already selected or synchronized product identifiers when reconciling a retry.

Do not send raw OAuth tokens or unrelated private chat content to the model. When the model no longer needs full source material, prefer the normalized event state with source references over repeating every screenshot or message.
