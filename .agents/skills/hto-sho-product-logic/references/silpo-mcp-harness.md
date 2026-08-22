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
2. **Plan without cart mutation:** validate that the shopping plan is current and sufficiently resolved for product matching.
3. **Find candidates:** use available catalog/search tools, current price/availability data, and event constraints to propose specific products.
4. **Validate mapping:** ensure every selected item maps back to a generic plan need and violates no allergy or restriction. Keep missing or ambiguous matches visible.
5. **Mutate only on explicit cart action:** create or update the reviewable cart for the current plan revision.
6. **Verify:** read the resulting cart or use the MCP response to confirm identifiers, quantities, failures, and checkout URL when the server exposes them.

Catalog searches and profile reads are non-cart discovery. Adding, removing, or changing cart lines is an external mutation and requires the user-initiated cart-sync action.

## Selection Rules

- Match by suitability first, then availability, quantity/pack fit, budget, and preference. Do not optimize price by violating a safety constraint.
- Do not infer that a product is safe from its title alone when allergy or dietary suitability depends on ingredients or metadata that MCP did not provide.
- Keep generic plan items separate from chosen Silpo SKUs so the plan remains understandable and can be rematched later.
- Do not add a substitute that materially changes the menu or violates an explicit preference without surfacing it for review.
- If no safe or suitable candidate is available, leave the need unresolved instead of forcing a product into the cart.

## Mutation and Retry Rules

- Bind every sync attempt to the authenticated user, event, and current plan revision.
- The discovered server exposes the authenticated user's current cart, not a separate create-cart operation. Inspect the cart before mutation and preserve unrelated products the user added outside Хто Шо?.
- Prefer an MCP-supported absolute quantity update that converges on the intended cart. The discovered add/update tool is annotated non-idempotent, so re-read the cart before any retry and never blindly repeat a timed-out call.
- Never clear the whole cart during normal event synchronization. Remove only a line that Хто Шо? previously added for this event and can identify reliably.
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
