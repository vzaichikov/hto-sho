---
paths:
  - 'app/{Models,Services}/Harness*.php'
---

# Models Services

## Harness logs are sanitized and short-lived
Event harness runs keep request and response payloads for 90 days. Redact authorization credentials, tokens, secrets, cookies, certificates, and image data URLs before persistence; store only image MIME, byte count, and SHA-256.
