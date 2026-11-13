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

## Health

- `GET /health/live` — process up (no I/O)
- `GET /health/ready` — database (and redis when required); `503` when a check fails

## Next

- [Configuration](configuration.md)
- [Upgrade](upgrade.md)
- [Backup](backup.md)
- [MCP and REST usage](api-usage.md)
