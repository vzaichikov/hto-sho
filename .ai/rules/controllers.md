---
paths:
  - 'app/{Actions,Jobs,Services,Http/Controllers}/**/*Cart*.php'
---

# Controllers

## Silpo MVP uses only an already prepared cart
For the MVP, never create a Silpo cart, choose a store or address, checkout, pay, or clear the cart. Require the user's current Silpo cart to already have a valid delivery or pickup route and slot; otherwise stop with the Ukrainian instruction to open Silpo and prepare it. Search exactly one need per MCP product-search call, stage selections, then perform one verified absolute batch update while preserving unrelated cart items.

## Allow explicit same-route expired-slot refresh
The owner may explicitly confirm the displayed nearest available slot when the current Silpo slot expires. Re-read the cart, preserve the exact branch, delivery type, address, shipments, products, and preferences, bind the write to route/current-slot fingerprints, update only the timeslot, and verify by read-back. Store/address/delivery-type selection remains a separate reviewed flow.

## Review fulfilment before Silpo product matching
The old prepared-cart-only MVP rule is superseded for event cart starts. The owner must review the current Silpo address, branch, delivery type, shipments, and live slot or explicitly choose a supported alternative. Bind the final choice to encrypted owner/event-scoped state, serialize and re-read before writing, verify exact read-back while preserving product lines, then and only then launch product matching. Never checkout, pay, clear items, or change bonuses, promos, certificates, or preferences.

## Reset-first cart contract supersedes preservation rules
For every newly initiated event cart run, the reset-first contract supersedes the old prepared-cart, preserve-unrelated-products, keep-route, and never-clear rules. After explicit consent, back up the complete cart encrypted, clear all products, prove empty readback, require a fresh route write/readback, reject foreign lines, and preserve the approved staged set plus safe external error details for exact retry.
