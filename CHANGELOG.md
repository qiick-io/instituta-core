# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Section embeddings via `laravel/ai` (issue #65): `AI_EMBEDDINGS_ENABLED` off by default, local OpenAI-compatible provider, `instituta:embed`, `GET /api/v1/similar` (perimeter-scoped), JSON vectors on `item_sections`, re-index when the model version changes. Tests use `Embeddings::fake()`.
- Scoped brain graph at `/brain/graph/{item?}` via `@xyflow/react` with an in-app radial layout (issue #49).

- Browser axe smoke via `pestphp/pest-plugin-browser` and `tests/Browser/A11ySmokeBrowserTest.php`; `ui-quality` now passes `axe-test` so `ui-quality / accessibility` runs (issue #52).
- `brain:write` proposal contract surface (issue #54): `proposals` table, REST `/api/v1/proposals`, MCP `instituta_propose` / `instituta_list_proposals` / `instituta_update_proposal`, kill switches `mcp.write_enabled` / `api.write_enabled` (off by default), audit + activity without bodies.
- Human UI to list and accept/reject write proposals at `/brain/proposals` (issue #55).
- Accepted proposals materialise as reviewable git changes (issue #53): `MaterialiseProposalJob` writes under `storage/app/instituta/proposals/{id}` (never mutates a local source in place), pushes a branch for `git`/`github`, opens a GitHub PR when a token is set; the brain index is unchanged until a later sync.

### Changed

- `docs/FORMAT.md` and `docs/CONTRACTS.md` are the approved working contract (issue #50). Until `v1.0.0-beta.1` they may still change in PRs labelled `area:contract` with a CHANGELOG entry and updated tests; from `v1.0.0-beta.1` they freeze (additive only).
- `docs/CONTRACTS.md` documents write proposal operations (§3.10–§3.12); keys may hold `brain:write` only when a write kill switch is on and the role is owner/member.

[Unreleased]: https://github.com/qiick-io/instituta-core/compare/HEAD...HEAD
