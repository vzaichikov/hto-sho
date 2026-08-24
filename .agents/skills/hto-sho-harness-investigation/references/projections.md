# Safe Harness Projections

Use Eloquent or read-only SQL. These are shapes, not permission to print the raw JSON columns.

## Entry timeline

Allowlist:

```text
id, harness_run_id, sequence, kind, title, status, status_code, duration_ms,
OCTET_LENGTH(request_payload), SHA2(request_payload, 256),
OCTET_LENGTH(response_payload), SHA2(response_payload, 256)
```

## Responses API entry

Extract only:

```text
response_payload.model
response_payload.status
response_payload.reasoning.effort
response_payload.usage.output_tokens_details.reasoning_tokens
types of response_payload.output[*]
types and byte lengths of response_payload.output[*].content[*]
```

Find the `message` item by `type`, then the content item whose type is `output_text`. Decode that text locally and select only the business fields required by the investigation, for example:

```text
action, selected_product_id, query, reason, question,
audit.complete, audit.revisit_need_key, audit.revisit_query
```

Never assume reasoning is `output[0]` or the message is `output[1]`.

## Catalog tool entry

Extract only:

```text
tool name, search query, HTTP status, result count,
candidate product ID, candidate display name, availability marker
```

Start with counts. Add names only when needed to prove query quality or candidate suitability. Do not emit full candidate arrays, descriptions, images, OAuth envelopes, or cart payloads.

## Platform rejection bisect

Start with metadata and hashes. Add one group per retry:

1. titles and statuses;
2. tool names and queries;
3. output item/content types and byte counts;
4. decoded business action fields;
5. one exact prompt fragment only when the earlier groups cannot explain the rejection.

Record which group first reproduces the rejection. Never add `encrypted_content`.
