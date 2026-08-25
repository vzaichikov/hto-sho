---
paths:
  - 'app/{Actions,Jobs,Services,Http/Controllers,Http/Requests,Contracts,Models}/**/*.php'
---

# Requests Contracts Models

## Reset Silpo cart before every new fulfilment run
A new Silpo cart run must not read the remote cart before explicit reset consent. After consent, persist the complete cart snapshot encrypted and event-scoped, clear all products, verify the same cart is empty, then require a fresh place/store/time write and readback before catalog work. Never merge foreign products; a replayed reset token must not clear changed non-empty contents. Keep final SKU review separate, and preserve the approved staged set plus safe Silpo error details for guarded commit retry.
