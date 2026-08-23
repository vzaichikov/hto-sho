---
name: hto-sho-product-logic
description: Apply the Хто Шо? product model when designing or changing event-source intake, AI extraction and synthesis, participant food constraints, shopping plans, or Silpo MCP cart synchronization. Use for product behavior and architecture; skip deployment-only and purely visual work.
---

# Хто Шо? Product Logic

`Хто Шо?` turns messy group-chat evidence into a reviewed food-shopping plan and a draft Silpo cart for a small real-life event.

Read [references/business-logic.md](references/business-logic.md) before changing product behavior, data semantics, prompts, analysis jobs, or event/cart states.

When the task touches OAuth, MCP tool use, product matching, or cart synchronization, also read [references/silpo-mcp-harness.md](references/silpo-mcp-harness.md).

When implementing or reviewing concrete Silpo tool calls, also read the dated live inventory and core schemas in [references/silpo-mcp-tools.md](references/silpo-mcp-tools.md). Re-run MCP discovery before relying on that snapshot because the server advertises tool-list changes.

## Product Boundary

- The app is not a messenger and does not require direct Telegram or Viber integration. Text and screenshots from any chat are input evidence.
- The app is not a general grocery-list generator. Its decisions are grounded in one event, its participants, their food constraints, their commitments, and optional budget/context.
- OpenAI and Ollama are interchangeable analysis providers behind the harness. Provider choice must not change the product's semantic contract.
- Silpo MCP is the authenticated retail integration for profile, product, and cart operations. Do not replace it with scraped catalog or cart automation unless the user explicitly changes this product decision.
- The outcome is a reviewable cart. Never place, pay for, or otherwise finalize an order.

## Required Reasoning Flow

1. Ingest all event sources cumulatively and preserve their provenance.
2. Extract facts, interpretations, contradictions, and unresolved questions without presenting guesses as facts.
3. Synthesize one current event state covering attendance, preferences, restrictions, allergies, drinks, responsibilities, budget, and event context.
4. Build quantities from the current state and subtract items that participants committed to bring.
5. Produce a structured shopping plan with warnings and enough rationale for a person to review it.
6. Map the current plan to Silpo products only through MCP and synchronize the cart only after the user initiates that external mutation.
7. Keep the cart tied to the exact event-state revision used to build it.

## Invariants

- Treat a catalog product that explicitly contains a forbidden allergen or contradicts a dietary restriction as a hard rejection. After bounded detail checks, missing catalog disclosure may be staged for MVP review only with an `unverified` evidence grade and an unmistakable package-check warning; never describe it as proven safe.
- Prefer the newest explicit correction when sources conflict, but preserve the conflict when chronology or intent is uncertain.
- Do not silently invent attendance, preferences, quantities, products, prices, availability, or participant commitments.
- Adding or changing source evidence invalidates derived results. A plan is current only for the event-state revision that produced it; a cart is current only for that plan revision.
- Make retries idempotent. Reprocessing a source or retrying cart synchronization must not duplicate evidence or cart lines.
- Keep event ownership, OAuth identity, tokens, source images, and derived personal food information scoped to the authenticated user.
- Keep uncertainty visible. Prefer a full reviewable cart with explained same-role substitutions and evidence warnings; never convert missing evidence into false certainty.

## Working with Existing Code

Inspect the repository before assuming which target capabilities are implemented. Preserve existing event-state and cart-version semantics unless the user explicitly requests a redesign. Reuse current names and schemas where they express the concepts above; this skill defines business meaning, not a mandatory class layout.

Use `$hto-sho-brandbook` as well for customer-facing copy or UI, `$laravel-best-practices` for Laravel implementation, `$hto-sho-local-qa` for authorized authenticated local QA, and `$hto-sho-production-deploy` only when deployment is explicitly requested.
