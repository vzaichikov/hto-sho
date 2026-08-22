---
paths:
  - 'app/{Actions,Jobs,Services}/**'
---

# Actions Jobs Services

## Keep plan corrections tied to the list the user saw
Store a plan correction as EventSource origin=plan_correction with an immutable base_plan snapshot in metadata. Summary receives only the correction text and origin; the shopping-plan builder may use base_plan only to resolve relative wording. Never reapply a relative correction against a later generated plan.
