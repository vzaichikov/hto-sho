---
name: hto-sho-local-qa
description: Perform authenticated local browser QA for the Хто Шо? Laravel app without starting OAuth. Use when a user explicitly authorizes reusing an existing local user or a temporary local auth bypass; never use for production or account creation.
---

# Хто Шо? Local Authenticated QA

## Safety Boundary

- Prefer an already authenticated Browser tab when one is available and belongs to the intended local user.
- Never start or repeat OAuth when the user says not to.
- Use the helper only after explicit authorization to bypass or mock local authentication.
- The helper must refuse any environment except `local` or `testing`, any non-loopback network database, and any session driver except `database`.
- Reuse an explicitly selected existing user ID. Do not create, seed, update, or impersonate a different user.
- Keep generated cookie artifacts outside the repository and never print their cookie value to chat or logs.
- Destroy the temporary session and artifact immediately after browser QA, including after a failed check.
- This workflow does not authorize production access, production data changes, or deployment.

## Workflow

1. Resolve the exact local app URL and inspect existing users read-only.
2. Create a temporary session artifact for the chosen existing user:

   ```bash
   php .agents/skills/hto-sho-local-qa/scripts/local-session.php create \
       --user-id=64 \
       --output=/tmp/hto-sho-qa-session.json \
       --minutes=30
   ```

3. Read the artifact only inside the browser automation process. Set its cookie on the exact local URL before visiting an authenticated route.
4. Confirm the rendered user name or owned data proves the expected identity before exercising any interaction.
5. Prefer read-only UI checks. Do not submit mutating forms unless the current user request requires that mutation.
6. Always clean up:

   ```bash
   php .agents/skills/hto-sho-local-qa/scripts/local-session.php destroy \
       --artifact=/tmp/hto-sho-qa-session.json
   ```

Report that OAuth was not invoked, which user ID was reused, and whether cleanup succeeded. Never include the cookie value or session ID.
