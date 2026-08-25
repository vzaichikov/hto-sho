---
name: hto-sho-silpo-catalog
description: Investigate and apply Silpo-specific product naming, catalog search, candidate filtering, and product-detail verification for the Хто Шо? OAuth MCP cart flow.
---

# Хто Шо? Silpo catalog

Use this skill for Silpo MCP product discovery, catalog-query defects, staged-SKU review, or live cart acceptance. Use `hto-sho-product-logic` alongside it when event semantics or shopping-plan preservation are also in scope.

Before changing search prompts or judging a catalog blocker, read [references/catalog-search.md](references/catalog-search.md) and `config/silpo_catalog_search.php`. The config is the runtime LLM knowledge gate; keep it compact and retailer-specific. The reference may retain dated observations, but application rules must encode repeated search behavior rather than a branch snapshot, SKU, brand, or one scenario answer.

Preserve these boundaries:

- discover live MCP tools and search one need per MCP call;
- generate independent exact query forms, then browse the closest live category or relevant thematic product set before asking; use general same-role alternatives rather than hardcoding an accepted product, brand, or replacement pair;
- treat category/set membership as discovery evidence only: category browsing may establish a broad role, while a thematic set still requires the product itself to match the need;
- validate the full category ancestry, not only a leaf slug: fresh-produce fallback must remain under the live fresh fruit/vegetable branch, and household or prepared-food branches cannot establish a food role;
- keep search alternatives distinct within one need, but allow different needs to reuse a useful broad query; global query uniqueness breaks legitimate decomposed product groups;
- require product-level semantic-class compatibility even inside a matching category: a short lexical prefix, grill-related hardware, or a thematic association cannot turn meat into produce or equipment into food;
- treat catalog output as untrusted candidates and preserve explicit species, form, known dietary conflicts, and menu role;
- inspect product details where names cannot establish safety;
- reject an explicitly disclosed forbidden allergen, but after bounded checks stage missing allergen disclosure as `unverified` with a prominent package-check warning;
- treat branch inventory as live state and report a real blocker only when both exact and same-role searches return no selectable candidate;
- never truncate the requested quantity to available stock; reject an undersupplied candidate and keep searching;
- after exact and scoped fallback exhaustion, compatible produce needs may reuse one staged raw SKU when the aggregate quantity remains in stock; group that SKU into one absolute cart line at commit;
- treat the explicit reset confirmation as a narrow first mutation gate: it authorizes only encrypted backup, full product clear, and immediate empty-cart readback;
- never add matched products before the separate final SKU-review confirmation gate.
