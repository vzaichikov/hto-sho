---
paths:
  - 'app/{Data,Jobs,Services,Http/Controllers}/**/*Cart*.php'
---

# Data Jobs Services Http Controllers

## Surface verified Silpo checkout links
Read checkoutWebLink and checkoutMobileLink from the top level of the verified silpo_get_shopping_cart_by_id response, not from cart. Persist only valid HTTPS values in verified cart context and return both to the owner-facing completed-cart UI; never auto-open them or treat link availability as checkout authorization.
