---
paths:
  - 'app/{Jobs,Services,Http/Controllers}/**/*Cart*.php'
---

# Jobs Services Http Controllers

## Minimum order does not block cart building
Silpo may return order.cost.min for an empty or below-minimum draft cart. Ignore this validation during fulfilment review and product writes because adding products is the operation that can satisfy it; still require a valid route and slot, preserve final confirmation, and verify the absolute batch by cart read-back.
