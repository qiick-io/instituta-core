# Instituta

> **Working name.** "Instituta" is a working name pending legal clearance. Do not use it publicly, and do not register, publish or file anything under it, until the maintainer says the name is cleared. This repository is private.

Instituta is a qiick product: the operating **company brain**. It holds a company's (or a freelancer's, or one person's) brand, coding rules, design systems and shadcn registries, ideas and plans, so that agents and humans query the same content under one contract. It is AI-first, and the human view is first-class too: a map of the whole brain, a reading view, the resolved view per product and a scoped graph. Content is owned by the user: qiick supplies the product, not the corpus. Intended for open source and self-hosted installs (and later qiick Cloud); **nothing is published to Packagist, GHCR, npm or a public domain until the maintainer says so**.

## One repository

`qiick-io/instituta-core` is the whole product, the same way `externa-core` is for Externa: one self-hosted Laravel app with the MCP server, the REST API, the Artisan CLI and the UI. Inside it, the brain format reader and resolver are a self-contained module, so they could be extracted later. Any extra piece added later gets a name that says what it is (for example `instituta-cli` or `instituta-sdk-php`), never a second "core".

Git is the durable store: a brain is a git repository (or a path in one), and the graph is derived from its files.

## Status

Private beta toward `v1.0.0-beta.1`. Contracts: [`docs/FORMAT.md`](docs/FORMAT.md), [`docs/CONTRACTS.md`](docs/CONTRACTS.md). Ops docs: [installation](docs/installation.md), [configuration](docs/configuration.md), [upgrade](docs/upgrade.md), [backup](docs/backup.md), [MCP/REST](docs/api-usage.md). Changelog: [`CHANGELOG.md`](CHANGELOG.md). Kickoff for agents: [`docs/agents/cursor-kickoff.md`](docs/agents/cursor-kickoff.md).

## Install and upgrade (self-hosted)

Day-to-day local development uses Laravel Herd (`https://instituta-core.test` after `herd link` / `herd secure`) and `composer run dev`.

For a fresh install (once Packagist publish is allowed by the maintainer):

```bash
composer create-project qiick/instituta-core
cd instituta-core
php artisan instituta:install
```

`create-project` only copies `.env` and generates the app key. `instituta:install` writes `.env` (APP_NAME, APP_URL, `--stack=minimal|redis`), migrates SQLite by default, runs `instituta:sync-permissions` and seeds built-in roles, optionally builds the frontend, and bootstraps the first organisation / own company / owner user (super-admin) / principal / owner API key. Tenancy ACL stays on brain grants/roles; app admin UI uses Spatie `PermissionEnum` / `RoleEnum`. Non-interactive CI mode: `--no-interaction --force --no-npm`.

Updates use the upgrade channel, never a second `create-project`:

```bash
php artisan instituta:upgrade
```

That migrates, syncs permissions, clears caches, and runs version-specific steps. See `php artisan instituta:install --help` and `php artisan instituta:upgrade --help`.

Health probes (auth-free): `GET /health/live` (process up) and `GET /health/ready` (database, and redis when cache/queue/session/broadcasting use it; `503` when a check fails).

### Docker (image build)

```bash
cp .env.docker.example .env
# set APP_KEY
docker compose up --build -d
```

Multi-arch bake (no push): `docker buildx bake -f docker-bake.hcl production`. Publishing to GHCR is **maintainer-only** on a `v*` tag; agents never push images.

## Contract surfaces

| Surface | Entry           | Notes                                                         |
| ------- | --------------- | ------------------------------------------------------------- |
| REST    | `/api/v1/*`     | Bearer API key; OpenAPI at `/api/v1/openapi.json`             |
| MCP     | `/mcp` (HTTP)   | Same perimeter as REST; enable via `instituta.mcp.enabled`    |
| Artisan | `instituta:*`   | Read, sync, embed, export, freeze, install, upgrade           |
| UI      | Inertia / React | Map, tree, reading, resolved, graph, proposals, agency portal |

Writes go through **proposals** (`brain:write`): kill switches `INSTITUTA_API_WRITE_ENABLED` / `INSTITUTA_MCP_WRITE_ENABLED` are off by default. Optional section embeddings: `AI_EMBEDDINGS_ENABLED` (off by default) and `GET /api/v1/similar`.

## Search drivers

| Driver   | Engine             | Notes                                                                                                                                 |
| -------- | ------------------ | ------------------------------------------------------------------------------------------------------------------------------------- |
| `sqlite` | FTS5 virtual table | Default install and Pest                                                                                                              |
| `pgsql`  | `tsvector` + GIN   | Reference driver (PostgreSQL 17 in CI)                                                                                                |
| MySQL    | —                  | **Not supported.** Search answers with a clear error (HTTP 501 / MCP tool error). A FULLTEXT driver is tracked for a later milestone. |

## Security and tenancy

Every read and write is scoped to an organisation perimeter (grants + API key scope). Cross-tenant isolation is covered by Pest; the never-ingest denylist refuses Klover, Cybergrant and Zerotredici. Do not expose a public install until HTTP security headers and a legal name clearance (`#76`) are settled.

## Licence

[MIT](LICENSE).
