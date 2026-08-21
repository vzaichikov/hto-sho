---
paths:
  - app/Services/SilpoOAuthClient.php
---

# Services

## Use Silpo public OAuth profile
For Silpo MCP OAuth, use Dynamic Client Registration with token_endpoint_auth_method=none and omit scope from registration and authorization requests. Keep PKCE S256, state validation, discovery, and resource parameters from laravel/mcp. Do not switch back to the package default mcp:use scope or confidential client_secret_post flow unless Silpo documents and verifies support.
