---
paths:
  - 'tests/{Feature,Live,Fixtures,Support}/**/*AiProductLogic*'
---

# Feature Live Fixtures Support

## Keep AI regressions semantic and live opt-in
Product AI regressions assert normalized event semantics and safety invariants, never exact summary wording. Reuse the JSON scenarios in tests/Fixtures/AiProductLogic. Tests in tests/Live call the configured provider only with AI_LIVE_REGRESSIONS=1 and must stay outside the default PHPUnit suites.
