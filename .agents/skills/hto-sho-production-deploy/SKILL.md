---
name: hto-sho-production-deploy
description: Safely deploy the Хто Шо? Laravel application to hto-sho.hobotix.dev. Use when the user asks to deploy, publish, release, update production, or check production deployment readiness for this repository.
---

# Хто Шо? Production Deploy

## Core Rules

- Deploy only after the user explicitly asks for a production deploy.
- Never seed production data. Do not run `db:seed`, `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`, or `db:wipe`.
- Use the ignored connection files in `.codex/`; never commit SSH credentials, production environment files, or generated deploy logs.
- Verify changes locally in proportion to their risk. Run focused tests for PHP changes and `npm run build` for frontend changes.
- Stop on a dirty or unpushed local worktree, failed checks, failed SSH, a dirty production worktree, branch mismatch, or a migration concern that needs review.
- Do not alter production data outside committed migrations explicitly included in the deployment.

## Standard Workflow

1. Inspect `git status --short` and the diff that will be deployed.
2. Run the relevant local tests and build, then commit and push the intended files if needed.
3. Run the guarded dry run:

   ```bash
   .agents/skills/hto-sho-production-deploy/scripts/deploy-production.sh --dry-run
   ```

4. Confirm it reports a clean local worktree, a pushed commit, the expected production root and branch, a clean production worktree, current and target SHAs, and readable migration status.
5. Only after a clean dry run, execute:

   ```bash
   .agents/skills/hto-sho-production-deploy/scripts/deploy-production.sh --execute
   ```

6. Verify the public HTTPS endpoint, production SHA/upstream parity, clean Git state, maintenance mode off, and migration status.
7. Report the deployed branch, before/after SHAs, migration result, asset build result, and any warnings.

## Script Behavior

The script reads `.codex/production.env` and connects through `.codex/production-ssh`.

Execute mode verifies local and production Git state, enables Laravel maintenance mode with a cleanup trap, pulls with `--ff-only`, installs production Composer dependencies, clears caches, installs and builds frontend dependencies from lockfiles, runs only forward migrations, optimizes Laravel, restarts queue workers, and returns the application to live mode.
