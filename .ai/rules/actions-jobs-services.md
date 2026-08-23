---
paths:
  - 'app/{Actions,Jobs,Services}/**'
---

# Actions Jobs Services

## Keep plan corrections tied to the list the user saw
Store a plan correction as EventSource origin=plan_correction with an immutable base_plan snapshot in metadata. Summary receives only the correction text and origin; the shopping-plan builder may use base_plan only to resolve relative wording. Never reapply a relative correction against a later generated plan.

## Use live catalog scopes after lexical search
For Silpo matching, discover categories and product sets live for the current branch/slot. After bounded text queries, browse the most specific matching category, then a relevant set; category membership may widen to the same role, while set membership still requires product-level identity. Neither path can override allergen, species, raw/prepared, alcohol, or other hard filters.
