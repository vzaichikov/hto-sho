---
paths:
  - '.agents/skills/hto-sho-local-qa/**'
---

# Hto Sho Local Qa

## Keep local QA sessions isolated
Authenticated QA may mint a short-lived database session only in local/testing, for an explicit existing user and loopback database. Never start OAuth or use this bypass outside local QA, and always destroy the session artifact after the run.
