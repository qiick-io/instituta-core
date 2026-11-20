# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Per-area access (#334): grants carry `areas` and `path_prefixes` lists (a migration carries the old single `area` and `path_prefix` over), user groups and API keys can be limited to some areas, one folder and (groups) a visibility, and the grants on one scope add up. **Contract:** the perimeter's `area` and `path_prefix` become `areas` and `path_prefixes` (lists; empty means no limit). Proposals, gap reports, notifications, sync reports, health and link rendering now stay inside the limits too; organisation-wide owner pages (clients, exports, activity log, agent calls) need an owner without limits. The demo seeds a "Marketing" group limited to brand, style and the glossary.
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

- Agent contract additions (#232–#239): embeddings of new and changed sections after every sync (when embeddings are on), a stable machine code on every contract error, batch section reads (`POST /api/v1/sections`, `instituta_get_sections`), a token estimate on every read, a ready-to-use context pack for a task (`GET /api/v1/context`), what changed since a date or version (`GET /api/v1/changes`), gap and contradiction reports from agents (`POST /api/v1/gaps`), and the brain's health after each sync (`GET /api/v1/health/brain`).
- Explorer (#260): a filesystem view of the brain (sources, folders, files) with the reading view beside it, replacing Map and Tree; a fixed file header with Propose change, generated-block notices (`<!-- name:begin -->` markers) and a mini-graph of the item's neighbours (#292, #296).
- Whole-brain graph (#261, #272–#291): a canvas graph of every item and link, laid out by d3-force in a Web Worker; nodes float, beams run along links, labels never overlap. Select to light neighbours with a preview card, Shift-click to trace the path between two items, drag nodes, minimap, pan and zoom, Constellations (grouped by area), Area and Review filters, colour by area or freshness, review signals, keyboard navigation with a list alternative, save as image; the view lives in the URL.
- Review inbox (#262): proposals and gaps as tabs under Review, with counts in the sidebar.
- Agent calls as an Activity tab with a standard detail panel (#263); client avatars and aligned client pages (#264).
- User groups like externa-core (#266), with brain access per group (#268).
- Brain assets (#314, #316): images (`png`, `jpg`, `gif`, `webp`, `avif`, `svg`) and PDFs enter the brain as files with metadata only (an optional `<file>.meta.yml` adds alt text, caption, tags), served inside the perimeter only (SVGs under a sandboxing CSP), previewed in the Explorer with Download and Used in, and shown where documents embed them; zip archives, fonts and Office files enter as download-only files. 20 MB limit; not a file manager.
- Document text (#318, #322): the text of PDFs (per page) and Office / OpenDocument files is read locally at sync time into searchable sections, through the same secret / PII scan as markdown (on a hit the text is withheld, the file stays). New dependency `smalot/pdfparser` (LGPL-3.0).
- Instituta's own logo and the Salvia theme preset by default (#271).
- `DemoAgencySeeder` (#323): a fictional agency, "Northwind Studio", for demos and screenshots.

### Changed

- Initial super admin follows the shared fleet password rule: `local`/`testing` keep the `.env` password, while other environments create the admin only with an explicit, non-default password that passes `Password::defaults()`, and warn which condition failed when they skip. Seeding outside local/testing never modifies an existing user; `instituta:install` and `instituta:create-user` use the same check.
- Never-ingest denylist is configurable via `INSTITUTA_INGEST_DENYLIST` (issue #369): comma-separated, trimmed, lowercased, default empty. Hardcoded organisation names removed from config, code defaults, docs and tests.
- `docs/FORMAT.md` and `docs/CONTRACTS.md` are the approved working contract (issue #50). Until `v1.0.0-beta.1` they may still change in PRs labelled `area:contract` with a CHANGELOG entry and updated tests; from `v1.0.0-beta.1` they freeze (additive only).
- `docs/CONTRACTS.md` documents write proposal operations (§3.10–§3.12); keys may hold `brain:write` only when a write kill switch is on and the role is owner/member.

- Pages moved out of `/brain` (#259): `/explorer`, `/graph`, `/review`, `/clients`, `/activity`; repositories and API keys under Settings; old `/brain/*` links redirect.
- Header (#300, #304): the command palette opens from a search icon next to the sidebar toggle; both show a tooltip with their shortcut. Settings is the last item of the sidebar's Platform group (#320).
- Icon segmented controls show the icon only, the name in a tooltip (#306, #307); the list pagination keeps a compact page window at large page counts (#298).
- app-ui pins moved to v0.9.0 (#302, #309).

### Fixed

- Instituta migrates on MySQL again: the `quotas` unique key on `scope_type`, `scope_id`, `metric` and `period` was 3164 bytes under utf8mb4, past InnoDB's 3072-byte limit; the three strings are now 64 characters and the key 872 bytes (#356).
- `vitest` runs only the repo's own `resources/js` tests, no longer the example specs shipped in `vendor/`; the brain graph code uses full-word names, so `lint:standards` is green again (#313).
- Graph UI now returns 404 for an explicit item id outside the session perimeter (issue #75), matching reading-view behaviour.

- Brain pages follow the app-ui page pattern; section markdown renders in the reading view; wiki links resolve by title as well as file name (#245–#251).
- The Explorer header no longer repeats the summary or the title the breadcrumb shows (#294, #304); HTML comments are not shown as text.

### Security

- Isolation review pass (issue #75): extended cross-tenant coverage for graph UI, proposals UI, and the never-ingest denylist. Follow-up: ship HTTP security headers middleware before public exposure.

### Upgrade notes

These notes are for the eventual `v1.0.0-beta.1` cut (the release PR moves this section under `[1.0.0-beta.1] - YYYY-MM-DD`).

1. Run `php artisan instituta:upgrade` (migrate, sync permissions, clear caches). Do not re-run `composer create-project` to update.
2. Optional embeddings: set `AI_EMBEDDINGS_ENABLED=true` and configure an OpenAI-compatible embeddings endpoint (`config/ai.php`); then run `php artisan instituta:embed`.
3. Write proposals stay off until `INSTITUTA_API_WRITE_ENABLED` / `INSTITUTA_MCP_WRITE_ENABLED` (or the matching `config/instituta.php` kill switches) are enabled and keys hold `brain:write`.
4. Search: PostgreSQL (tsvector) is the production driver; SQLite FTS5 for local/tests; MySQL search remains unsupported (501).
5. After the first tag, migrations are forward-only (`database/migrations/.frozen` + `migrations-frozen` workflow). Schema changes need a new migration.
6. Never-ingest denylist (#369): the built-in organisation names are gone. An install that relied on them must set `INSTITUTA_INGEST_DENYLIST` (comma-separated tokens matched case-insensitively on name, remote URL and path).

[Unreleased]: https://github.com/qiick-io/instituta-core/compare/HEAD...HEAD
