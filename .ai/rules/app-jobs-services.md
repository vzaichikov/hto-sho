---
paths:
  - '{app/{Jobs,Services}/**/*Cart*.php,config/goose_cart_phrases.php}'
---

# App Jobs Services

## Show the exact Silpo text query in cart status
When a cart step corresponds to a real text-search call or a scheduled lexical retry, pass that normalized query in step context as `query` and render it through the query-aware Goose phrase pool. Keep catalog-scope browsing, decision retries, and other non-text-search steps generic so the feed never claims a synonym that was not actually sent.
