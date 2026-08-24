---
name: hto-sho-harness-investigation
description: Investigate Хто Шо? HarnessRun and HarnessEntry LLM or MCP behavior without loading raw request and response envelopes into the model context. Use for run comparison, provider failures, reasoning/output inspection, prompt-policy incidents, catalog search traces, and run-fix-run evidence.
---

# Хто Шо? Harness Investigation

Investigate from small, explicit projections. Keep raw harness payloads in the database or a private temporary file; never print a whole request or response JSON into the agent context.

## Non-negotiable boundary

- Start with run and entry metadata: IDs, sequence, kind, title, status, status code, duration, model, and byte counts.
- Treat Responses API `output[].type=reasoning`, `encrypted_content`, and transport metadata as non-business data. The harness sanitizer removes reasoning output items; exclude them from every projection of older runs as well.
- Treat `output_text` and its structured fields such as `action`, `reason`, `question`, and `audit` as business evidence. Keep only the fields needed for the current claim.
- Keep secrets, OAuth material, images, complete prompts, complete catalogs, and complete product-detail payloads out of the context.
- Do not select `request_payload` or `response_payload` directly. Extract allowlisted JSON paths, hashes, lengths, and counts in the database query.
- If a raw field is genuinely required, inspect it locally with a deterministic extractor and report only the minimal derived result.

## Investigation loop

1. Identify the exact run and business oracle before reading payload content.
2. Project the entry timeline using metadata only.
3. For LLM entries, extract the effective model, reasoning effort and token count, output item types, and selected business fields from `output_text`.
4. For MCP entries, extract the tool name, query, result count, and only the candidate IDs/names needed to explain the decision.
5. Compare the smallest failing and passing projections.
6. Change one application behavior at a time, run the narrowest test, then repeat the same projection.
7. If an agent/platform prompt rejection recurs, bisect allowlisted field groups. Do not retry by pasting the same raw envelope.

Use [projection recipes](references/projections.md) for safe query shapes. Report run IDs and entry sequences so another person can inspect the authoritative raw record without copying it into an LLM prompt.

## Prompt accountability

The harness already records the application request before the external call. Refer to its entry ID as the authoritative application prompt record. The hidden platform-assembled Codex prompt is not available; do not claim otherwise. For prompt-policy debugging, record the explicit user text, command/query shape, selected field names, byte counts, and hashes in a private temporary audit file.
