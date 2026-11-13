# Upgrade

Never use `composer create-project` to update an existing install. Fetch the new code (git pull / release archive), install Composer and npm dependencies as needed, then:

```bash
php artisan instituta:upgrade --force
```

That runs `migrate --force`, notes the reserved permissions sync (brain grants cover ACL until `PermissionEnum` / `instituta:sync-permissions` ship), and clears caches. It does **not** run Composer or npm.

Flags: `--no-migrate`, `--no-permissions`, `--force` (required when `APP_ENV=production`). See `php artisan instituta:upgrade --help`.

## After upgrade

1. Read **Upgrade notes** in [`CHANGELOG.md`](../CHANGELOG.md) for the version you landed on.
2. Optional: `php artisan instituta:embed` if embeddings are enabled and the model version changed.
3. Confirm `GET /health/ready` returns `ok`.

## Docker

Rebuild or pull the image for the target tag, then bring Compose up. First boot may set `RUN_MIGRATIONS=true` in `.env`; afterwards you can set it false. Publishing images is maintainer-only.

## Related

- [Installation](installation.md)
- [Backup](backup.md)
- [Changelog](../CHANGELOG.md)
