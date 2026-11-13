# Backup

Instituta’s durable corpus is **git** (registered sources). The app database holds the search index, tenancy, keys, proposals, activity and quotas.

## What to back up

1. **Database** — SQLite file (`database/database.sqlite`) or a logical dump of MySQL / PostgreSQL.
2. **`.env`** — off-box, encrypted; contains `APP_KEY` and tokens. Without `APP_KEY`, encrypted columns cannot be recovered.
3. **`storage/`** — logs, proposal materialisation worktrees under `storage/app/instituta/`, uploaded disks if any.
4. **Source remotes** — the git remotes you ingest; the app clone under `storage/instituta/sources/` can be re-synced.

## Suggested cadence

- Before every `instituta:upgrade` or schema-touching deploy: database + `.env` snapshot.
- Daily (or continuous) database backups in production.
- Keep at least one copy off the application host.

## Restore sketch

1. Restore `.env` and the database file/dump.
2. Restore `storage/` if needed.
3. `php artisan instituta:upgrade --force` (or `migrate --force`) then `GET /health/ready`.
4. Re-sync sources: `php artisan instituta:sync` if clones were discarded.

## Related

- [Upgrade](upgrade.md)
- [Configuration](configuration.md)
