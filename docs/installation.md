# Installation

> **Working name.** "Instituta" is a working name pending legal clearance. This repository stays private; do not publish Packagist, GHCR, npm or a public domain until the maintainer says so.

Instituta is a single self-hosted Laravel app (`qiick/instituta-core`): MCP, REST, Artisan and the Inertia UI in one tree.

## Requirements

- PHP 8.4+ with SQLite (default), or MySQL 8 / PostgreSQL 17
- Composer 2, Node.js 24, npm
- Optional: Redis when using the `redis` install stack (queue/cache/broadcast)

## Composer create-project (when Packagist publish is allowed)

```bash
composer create-project qiick/instituta-core
cd instituta-core
php artisan instituta:install --no-interaction --force
```

`create-project` only copies `.env` and generates the app key. `instituta:install` writes APP_NAME / APP_URL / stack, migrates, optionally builds the frontend (`--no-npm` to skip), and bootstraps the first organisation, own company, owner user, principal and owner API key.

Useful flags: `--app-name=…`, `--app-url=…`, `--stack=minimal|redis`, `--no-owner`, `--org-name=…`, `--owner-email=…`, `--owner-password=…`. See `php artisan instituta:install --help`.

The first admin's password follows one fleet rule in `db:seed`, `instituta:install` and `instituta:create-user`. In `local` and `testing` the seeded password comes from `.env` (`password` in `.env.example`). Elsewhere it must be explicitly set, must not be `password` in any case, and must pass the instance password policy (`Password::defaults()`); a skipped seed warns which condition failed. Seeding outside local/testing never changes an existing user.

## Local development (Herd)

```bash
herd link && herd secure
composer install && npm ci
php artisan instituta:install --force --no-npm   # or migrate:fresh --seed when disposable
composer run dev
```

App URL: `https://instituta-core.test` (Herd). One database per app; for a clean state use `php artisan migrate:fresh --seed` (never keep per-branch copies).

## Docker

```bash
cp .env.docker.example .env
# set APP_KEY
docker compose up --build -d
```

See the README Docker section and `docker-bake.hcl`. Image publish to GHCR is maintainer-only on a `v*` tag.

## Keeping the brain fresh

Two background processes keep the index in step with the brain repositories:

- **Scheduler:** run `php artisan schedule:run` every minute (cron: `* * * * * cd /path/to/instituta && php artisan schedule:run >> /dev/null 2>&1`). It runs `instituta:sync-due`, which queues a sync for every source whose interval has passed (`sync_interval_minutes`: 15 by default for `git` / `github`, off for `local`; change it with `instituta:source:add --sync-interval=`). A source still at the indexed commit costs only a `git fetch`.
- **Queue worker:** `php artisan queue:work` (or Horizon with Redis) runs the syncs.

For immediate updates, add a push webhook: `php artisan instituta:source:webhook <source-id>` prints the payload URL and a secret (shown once) to paste in the repository's webhook settings (GitHub: content type `application/json`, event `push`). Requests must be signed with `X-Hub-Signature-256` and include a delivery id (`X-GitHub-Delivery`, or `X-Gitea-Delivery` / `X-Forgejo-Delivery`); delivery ids are replay-protected after signature verification, missing ids are refused with 400, and replay store failures fail closed with 503. Standard Webhooks (`webhook-id` / `webhook-timestamp` / `webhook-signature`) are also accepted with the same source secret and are preferred when both header sets are present. `--disable` removes the secret. After each sync, materialised proposals whose change is now in the source are marked `merged`.

## Health

- `GET /health/live` — process up (no I/O)
- `GET /health/ready` — database (and redis when required); `503` when a check fails

## Next

- [Configuration](configuration.md)
- [Upgrade](upgrade.md)
- [Backup](backup.md)
- [MCP and REST usage](api-usage.md)
