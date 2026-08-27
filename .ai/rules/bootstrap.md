---
paths:
  - '{bootstrap/app.php,.env,keys.env,.gitignore}'
---

# Bootstrap

## Load private secrets from keys.env
Keep real runtime secrets in the ignored root keys.env file with mode 0600. bootstrap/app.php loads it before Laravel loads .env, so explicit process variables still win and .env remains the non-secret fallback. Never commit keys.env or copy its values into tracked examples.
