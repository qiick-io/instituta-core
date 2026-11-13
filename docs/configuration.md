# Configuration

Environment lives in `.env` (never commit secrets). Defaults favour SQLite + database queue/cache/session (`--stack=minimal`).

## Application

| Variable | Purpose |
| -------- | ------- |
| `APP_NAME` | Display name |
| `APP_URL` | Public URL (Herd, reverse proxy, or Compose port) |
| `APP_KEY` | Encryption key (`php artisan key:generate` if empty) |
| `APP_ENV` / `APP_DEBUG` | `local` / `true` in dev; `production` / `false` in prod |

## Database and stack

| Variable | Notes |
| -------- | ----- |
| `DB_CONNECTION` | `sqlite` (default), `pgsql`, or `mysql` |
| `QUEUE_CONNECTION` | `database` (minimal) or `redis` |
| `CACHE_STORE` | `database` or `redis` |
| `SESSION_DRIVER` | `database` (typical) |
| `BROADCAST_CONNECTION` | `log` / `null` or `reverb` when Redis stack |

Search: PostgreSQL is the production FTS driver; SQLite FTS5 for local/tests; MySQL search returns 501.

## Contract surfaces

| Variable | Default | Purpose |
| -------- | ------- | ------- |
| `INSTITUTA_MCP_ENABLED` | (see config) | HTTP MCP at `/mcp` |
| `INSTITUTA_API_WRITE_ENABLED` | `false` | REST `brain:write` kill switch |
| `INSTITUTA_MCP_WRITE_ENABLED` | `false` | MCP write kill switch |
| `INSTITUTA_GITHUB_TOKEN` | empty | Optional remote sync / proposal materialisation |
| `AI_EMBEDDINGS_ENABLED` | `false` | Section embeddings + `/api/v1/similar` |

Full shapes: [`CONTRACTS.md`](CONTRACTS.md), [`config/instituta.php`](../config/instituta.php), [`config/ai.php`](../config/ai.php).

## Tenancy and denylist

Reads and writes are organisation-scoped (grants + API key perimeter). Ingest refuses denylist tokens `klover`, `cybergrant`, `zerotredici` (case-insensitive) on name, remote URL and path.

## Related

- [Installation](installation.md)
- [Upgrade](upgrade.md)
- [Backup](backup.md)
