---
paths:
  - app/Services/SilpoOAuthClient.php
  - app/Services/AiRequestFactory.php
  - 'app/Services/*'
---

# Services

## Use Silpo public OAuth profile
For Silpo MCP OAuth, use Dynamic Client Registration with token_endpoint_auth_method=none and omit scope from registration and authorization requests. Keep PKCE S256, state validation, discovery, and resource parameters from laravel/mcp. Do not switch back to the package default mcp:use scope or confidential client_secret_post flow unless Silpo documents and verifies support.

## Reuse the OpenAI-compatible AI request factory
Configure AI through services.ai using AI_PROVIDER (openai or ollama), AI_MODEL, and AI_API_KEY. Both providers use their /v1 OpenAI-compatible endpoints; create outbound AI clients through AiRequestFactory.

## Separate LLM instructions from runtime user data
For every OpenAI-compatible LLM request, keep task, safety, and output-format instructions in OpenAI `instructions` or an Ollama `system` message. Put only runtime JSON and images in the `user` message. Apply the same boundary to retries and repair prompts, and cover both provider payload shapes in tests.

## Keep synthesized shopping requirements atomic
Context synthesis must emit one independently searchable product per shopping_requirements item. Split explicitly named grouped lists, keep the product identity alone in name, and move audience or purpose qualifiers such as for a participant into constraints. Do not invent a decomposition when the source names only a broad need.

## Limit Goose questions to menu and headcount
Event synthesis may ask only about menu choices, food or drink safety, a specific food contribution, or a genuinely unknown total participant count. Never ask for product, portion, weight, volume, or package amounts; the shopping planner estimates them from headcount. Never ask logistics or roster names when total headcount is known, and repair must remove legacy out-of-scope questions even when they have a key.
