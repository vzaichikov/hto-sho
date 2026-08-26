---
paths:
  - 'app/{Actions,Jobs,Services}/**'
---

# Actions Jobs Services

## Keep plan corrections tied to the list the user saw
Store a plan correction as EventSource origin=plan_correction with an immutable base_plan snapshot in metadata. Summary receives only the correction text and origin; the shopping-plan builder may use base_plan only to resolve relative wording. Never reapply a relative correction against a later generated plan.

## Use live catalog scopes after lexical search
For Silpo matching, discover categories and product sets live for the current branch/slot. After bounded text queries, browse the most specific matching category, then a relevant set; category membership may widen to the same role, while set membership still requires product-level identity. Neither path can override allergen, species, raw/prepared, alcohol, or other hard filters.

## Keep Silpo cart harness engines isolated
The persisted EventCartRun harness_mode selects the engine at dispatch time. Orchestrated is the default and must keep the existing PHP catalog/commit flow unchanged. Agentic uses native OpenAI remote-MCP only for per-need catalog selection and the human-confirmed absolute write/readback; reset, fulfilment, locks, fingerprints, local safety validation, and checkout prohibition remain under PHP control. Never silently fall back between engines.
