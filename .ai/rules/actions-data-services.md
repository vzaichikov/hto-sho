---
paths:
  - 'app/{Actions,Data,Services}/**/*Silpo*.php'
---

# Actions Data Services

## Allow Silpo to enrich reviewed fulfilment addresses
After a reviewed SelfPickup or NovaPoshta route write, verify the exact cart, delivery type, shipment branches, slot, and every selected address field. Allow the MCP readback to add canonical address metadata; do not require full address-array equality. Home-address matching keeps its stricter source-specific rules.

## Use reviewed target shipments for home route changes
After the reset-first empty-cart gate, DeliveryHome and WideAssortDelivery writes must send the reviewed target companyId and branchId in shipments, together with the target branchId. Reusing the previous route shipments can return HTTP 200 while leaving the cart on the old branch. Verify the exact readback branch and slot and require getReadyCart to succeed.
