---
paths:
  - '{app/Jobs/**/*Cart*.php,app/Services/AiRequestFactory.php,config/services.php}'
---

# App Jobs Services 2

## Keep cart AI timeout budgets ordered
AI requests may run for 180 seconds. Keep every cart queue-job timeout above the request timeout and below the active queue connection retry_after; production Ollama uses its provider-specific cart_job_timeout. Do not increase blind job retries just to mask a request-budget mismatch.
