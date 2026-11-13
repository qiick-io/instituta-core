# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Instance settings storage and admin pages (issue #123): `Setting` model + `SettingsRepositoryService`, General / Localization / Authentication & security, Appearance with Teal theme presets (copied from Signaculum/externa, trimmed).
- Section embeddings via `laravel/ai` (issue #65): `AI_EMBEDDINGS_ENABLED` off by default, local OpenAI-compatible provider, `instituta:embed`, `GET /api/v1/similar` (perimeter-scoped), JSON vectors on `item_sections`, re-index when the model version changes. Tests use `Embeddings::fake()`.
- Scoped brain graph at `/brain/graph/{item?}` via `@xyflow/react` with an in-app radial layout (issue #49).
- Reading-view light and dark screenshots under `docs/screenshots/` (issue #51); follow-up pattern proposal tracked as [app-ui#133](https://github.com/qiick-io/app-ui/issues/133).
- Browser axe smoke via `pestphp/pest-plugin-browser` and `tests/Browser/A11ySmokeBrowserTest.php`; `ui-quality` now passes `axe-test` so `ui-quality / accessibility` runs (issue #52).
- `brain:write` proposal contract surface (issue #54): `proposals` table, REST `/api/v1/proposals`, MCP `instituta_propose` / `instituta_list_proposals` / `instituta_update_proposal`, kill switches `mcp.write_enabled` / `api.write_enabled` (off by default), audit + activity without bodies.
- Human UI to list and accept/reject write proposals at `/brain/proposals` (issue #55).
- Accepted proposals materialise as reviewable git changes (issue #53): `MaterialiseProposalJob` writes under `storage/app/instituta/proposals/{id}` (never mutates a local source in place), pushes a branch for `git`/`github`, opens a GitHub PR when a token is set; the brain index is unchanged until a later sync.
- Organisation-scoped activity log UI at `/activity` (issue #78).
- Agency portal: clients list and scope switcher, create/edit client companies, manage products under a client, client subtree export zip (issues #79–#82).
- Offboarding erasure flow for client companies (issue #83).
- Per-tenant monthly quotas with enforcement on contract surfaces (issue #84).
- Session replay over `contract_calls` for organisation owners (issue #85).
- Auth-free `GET /health/live` and `GET /health/ready` probes with fail-closed readiness (issue #68).
- `database/migrations/.frozen` blob baseline so `migrations-frozen` binds before the first tag (issue #74).
- In-app notification centre (issue #129): database notifications, sidebar bell and drawer (`@qiick/notifications`), sound preference on the profile page; events for sync finished/failed, proposal opened/resolved, API key about to expire, and freeze activated (organisation-scoped).
- Optional command palette (issue #130): `@qiick/command-palette` + `keyboard-shortcuts` in `qiick-standards.json` `patterns`; ⌘K search of navigation, perimeter-scoped brain item titles, and allowed actions.

### Changed

- `docs/FORMAT.md` and `docs/CONTRACTS.md` are the approved working contract (issue #50). Until `v1.0.0-beta.1` they may still change in PRs labelled `area:contract` with a CHANGELOG entry and updated tests; from `v1.0.0-beta.1` they freeze (additive only).
- `docs/CONTRACTS.md` documents write proposal operations (§3.10–§3.12); keys may hold `brain:write` only when a write kill switch is on and the role is owner/member.

### Fixed

- Graph UI now returns 404 for an explicit item id outside the session perimeter (issue #75), matching reading-view behaviour.

### Security

- Isolation review pass (issue #75): extended cross-tenant coverage for graph UI, proposals UI, and never-ingest denylist tokens (`klover`, `cybergrant`, `zerotredici`). Follow-up: ship HTTP security headers middleware before public exposure.

### Upgrade notes

These notes are for the eventual `v1.0.0-beta.1` cut (the release PR moves this section under `[1.0.0-beta.1] - YYYY-MM-DD`).

1. Run `php artisan instituta:upgrade` (migrate, sync permissions, clear caches). Do not re-run `composer create-project` to update.
2. Optional embeddings: set `AI_EMBEDDINGS_ENABLED=true` and configure an OpenAI-compatible embeddings endpoint (`config/ai.php`); then run `php artisan instituta:embed`.
3. Write proposals stay off until `INSTITUTA_API_WRITE_ENABLED` / `INSTITUTA_MCP_WRITE_ENABLED` (or the matching `config/instituta.php` kill switches) are enabled and keys hold `brain:write`.
4. Search: PostgreSQL (tsvector) is the production driver; SQLite FTS5 for local/tests; MySQL search remains unsupported (501).
5. After the first tag, migrations are forward-only (`database/migrations/.frozen` + `migrations-frozen` workflow). Schema changes need a new migration.

[Unreleased]: https://github.com/qiick-io/instituta-core/compare/HEAD...HEAD
