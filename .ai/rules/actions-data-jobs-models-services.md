---
paths:
  - 'app/{Actions,Data,Jobs,Models,Services}/**'
---

# Actions Data Jobs Models Services

## Question keys represent decisions, not wording
Question keys are server-managed identities. Reuse an open key across paraphrases, treat organizer answers as closed decisions, and never derive a fresh identity merely because the LLM changed the wording. A recorded answer that exactly matches a cited current option closes that paraphrased alias.
