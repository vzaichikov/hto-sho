#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'USAGE'
Usage:
  deploy-production.sh --dry-run [--branch BRANCH]
  deploy-production.sh --execute [--branch BRANCH]

Deploys the pushed Хто Шо? Laravel Git branch to the configured production server.
Dry run is the default. Execute mode mutates production.
USAGE
}

mode="dry-run"
branch=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run)
            mode="dry-run"
            shift
            ;;
        --execute)
            mode="execute"
            shift
            ;;
        --branch)
            branch="${2:-}"
            if [[ -z "$branch" ]]; then
                echo "Missing value for --branch" >&2
                exit 2
            fi
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown argument: $1" >&2
            usage >&2
            exit 2
            ;;
    esac
done

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
project_root="$(cd "${script_dir}/../../../.." && pwd)"
env_file="${project_root}/.codex/production.env"
ssh_wrapper="${project_root}/.codex/production-ssh"

if [[ ! -f "$env_file" ]]; then
    echo "Missing ${env_file}. Configure production SSH locally before deploying." >&2
    exit 1
fi

if [[ ! -x "$ssh_wrapper" ]]; then
    echo "Missing executable ${ssh_wrapper}. Configure production SSH locally before deploying." >&2
    exit 1
fi

# shellcheck source=/dev/null
source "$env_file"

if [[ -z "${HTO_SHO_PRODUCTION_ROOT:-}" ]]; then
    echo "HTO_SHO_PRODUCTION_ROOT is not set in ${env_file}." >&2
    exit 1
fi

cd "$project_root"

if [[ -z "$branch" ]]; then
    branch="$(git branch --show-current)"
fi

if [[ -z "$branch" ]]; then
    echo "Could not determine the current Git branch. Pass --branch explicitly." >&2
    exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
    echo "Local worktree is not clean. Commit, stash, or revert local changes before deploying." >&2
    git status --short >&2
    exit 1
fi

upstream_ref="$(git rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null || true)"
if [[ -z "$upstream_ref" ]]; then
    echo "Current branch has no upstream. Push and set upstream before deploying." >&2
    exit 1
fi

local_head="$(git rev-parse HEAD)"
upstream_head="$(git rev-parse "$upstream_ref")"

if [[ "$local_head" != "$upstream_head" ]]; then
    echo "Local HEAD is not equal to upstream ${upstream_ref}. Push first, then deploy." >&2
    echo "local=${local_head}" >&2
    echo "upstream=${upstream_head}" >&2
    exit 1
fi

root_q="$(printf '%q' "$HTO_SHO_PRODUCTION_ROOT")"
branch_q="$(printf '%q' "$branch")"
mode_q="$(printf '%q' "$mode")"

"$ssh_wrapper" "HTO_SHO_PRODUCTION_ROOT=${root_q} BRANCH=${branch_q} DEPLOY_MODE=${mode_q} bash -se" <<'REMOTE'
set -euo pipefail

forbidden_commands='db:seed migrate:fresh migrate:refresh migrate:reset migrate:rollback db:wipe'

echo "Production root: ${HTO_SHO_PRODUCTION_ROOT}"
cd "$HTO_SHO_PRODUCTION_ROOT"

if [[ ! -f artisan ]]; then
    echo "Production root does not look like a Laravel app: missing artisan." >&2
    exit 1
fi

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "Production root is not a Git worktree." >&2
    exit 1
fi

current_branch="$(git branch --show-current)"
if [[ "$current_branch" != "$BRANCH" ]]; then
    echo "Production branch mismatch. current=${current_branch} expected=${BRANCH}" >&2
    exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
    echo "Production worktree is not clean. Refusing to deploy." >&2
    git status --short >&2
    exit 1
fi

git fetch --prune origin

before_sha="$(git rev-parse HEAD)"
target_sha="$(git rev-parse "origin/${BRANCH}")"

echo "Production branch: ${current_branch}"
echo "Current production SHA: ${before_sha}"
echo "Target production SHA: ${target_sha}"
echo "Forbidden production commands: ${forbidden_commands}"
echo "Migration status before deploy:"
php artisan migrate:status --no-interaction | sed -n '1,160p'

if [[ "$DEPLOY_MODE" == "dry-run" ]]; then
    echo "Dry run complete. No production changes were made."
    exit 0
fi

down_set=0
cleanup() {
    status=$?

    if [[ "$down_set" == "1" ]]; then
        php artisan up --no-interaction >/dev/null 2>&1 || true
    fi

    exit "$status"
}
trap cleanup EXIT

php artisan down --render="errors::503" --retry=60 --no-interaction || php artisan down --retry=60 --no-interaction
down_set=1

git pull --ff-only origin "$BRANCH"

composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan optimize:clear --no-interaction

npm ci --include=dev --ignore-scripts
npm run build

php artisan migrate --force --no-interaction
php artisan optimize --no-interaction
php artisan queue:restart --no-interaction

php artisan up --no-interaction
down_set=0
trap - EXIT

after_sha="$(git rev-parse HEAD)"

echo "Deploy complete."
echo "Previous production SHA: ${before_sha}"
echo "Deployed production SHA: ${after_sha}"
echo "Production Git status:"
git status --short --branch
echo "Migration status after deploy:"
php artisan migrate:status --no-interaction | sed -n '1,160p'
REMOTE
