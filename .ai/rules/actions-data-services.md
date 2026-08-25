---
paths:
  - 'app/{Actions,Data,Services}/**/*Silpo*.php'
---

# Actions Data Services

## Allow Silpo to enrich reviewed fulfilment addresses
After a reviewed SelfPickup or NovaPoshta route write, verify the exact cart, delivery type, shipment branches, slot, and every selected address field. Allow the MCP readback to add canonical address metadata; do not require full address-array equality. Home-address matching keeps its stricter source-specific rules.
