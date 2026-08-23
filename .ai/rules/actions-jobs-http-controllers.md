---
paths:
  - 'app/{Actions,Jobs,Http/Controllers}/**/*Cart*.php'
---

# Actions Jobs Http Controllers

## Require final confirmation before Silpo mutation
After final SKU audit, persist staged items in waiting_for_confirmation and stop. Both assisted and auto modes require the owner-scoped confirmation endpoint; confirmation must lock the run, revalidate the current plan and exact cart route/slot fingerprint, then dispatch one idempotent absolute commit.
