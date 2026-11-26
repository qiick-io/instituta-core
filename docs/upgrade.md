# Upgrade

Upgrade the PHP runtime to 8.4 or newer before installing the updated Composer dependencies. On Laravel Cloud, set the environment's PHP version before deploying.

Never use `composer create-project` to update an existing install. Fetch the new code (git pull / release archive), install Composer and npm dependencies as needed, then:

```bash
php artisan instituta:upgrade --force
```

That runs `migrate --force`, `instituta:sync-permissions` (creates missing permissions, grants new ones to `admin`, emits `resources/js/enums/permission-enum.ts`), and clears caches. It does **not** run Composer or npm. Brain tenancy ACL (grants/keys) stays separate from app-level Spatie roles.

Flags: `--no-migrate`, `--no-permissions`, `--prune-permissions`, `--force` (required when `APP_ENV=production`). See `php artisan instituta:upgrade --help`.

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
