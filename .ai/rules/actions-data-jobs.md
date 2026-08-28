---
paths:
  - 'app/{Actions,Data,Jobs}/**/*Cart*.php'
---

# Actions Data Jobs

## Start orchestrated cart search from the approved plan
Orchestrated cart harness v1 must project the current approved shopping-plan items deterministically and begin catalog search without an LLM preparation or plan reinterpretation call. Preserve minimum_distinct_products as distinct search slots and keep the revision/staleness guard. Agentic mode retains its separate preparation flow.
