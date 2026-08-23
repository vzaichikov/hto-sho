---
paths:
  - 'app/{Actions,Jobs,Services,Http/Controllers}/**/*Cart*.php'
---

# Controllers

## Silpo MVP uses only an already prepared cart
For the MVP, never create a Silpo cart, choose a store or address, checkout, pay, or clear the cart. Require the user's current Silpo cart to already have a valid delivery or pickup route and slot; otherwise stop with the Ukrainian instruction to open Silpo and prepare it. Search exactly one need per MCP product-search call, stage selections, then perform one verified absolute batch update while preserving unrelated cart items.
