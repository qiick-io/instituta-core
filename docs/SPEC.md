# Instituta — Specification (MVP v0.1.0)

> Status: working draft aligned with the kickoff decisions of 2026-11-12. Product docs `FORMAT.md` and `CONTRACTS.md` are the approved working contract (see those files for the freeze rule at `v1.0.0-beta.1`). Anything marked `[plan]` in the design note is direction only and is **out of this MVP** unless listed below.

## 1. Product

**Instituta** (working name; reserve **Cogitata**) is a standalone qiick product: the operating **company brain**. It holds brand, coding rules, design systems (shadcn registries), ideas and plans. It is AI-first and the human UI is first-class: agents and humans query the same content under **one contract** with **progressive disclosure**.

- Open source intent: MIT; self-hosted; later qiick Cloud.
- Content is **user-owned**: qiick supplies the product, not the corpus.
- Knowledge and policy plane, **not** a data-plane proxy: Instituta never proxies writes to other qiick products and never holds their keys.
- The Externa beta must not depend on Instituta.
- Git is the durable store; the graph is derived from the filesystem. MVP sync is **read-only**.
- Not a Gantt: three separate dates on knowledge items; no tickets, assignees, or progress bars.

**Repository decision (2026-11-12):** one private app repo `qiick-io/instituta-core` (Composer `qiick/instituta-core`, `"type": "project"`). There is no `instituta-app` and no separate core package. The brain format reader and resolver live in `app/Brain` as a self-contained module so they could be extracted later under a descriptive name — never a second "core".

Working name: keep the string in few places (`app.name`, `instituta:` Artisan namespace, locales). No public publish (Packagist, GHCR, npm, domain) until the maintainer says so.

## 2. Architecture (one app, two layers)

| Layer | Where | Responsibility |
|---|---|---|
| Brain module | `app/Brain`, `tests/Brain` | Format, adapters, reader, resolver, perimeter filter as pure functions, contract DTOs, denylist, secret/PII scanner. No Eloquent, no app facades, no `config()` / `env()`. |
| App | rest of `app/` | Tenancy, principals, keys, grants, sources, index, ingest jobs, search drivers, MCP/REST/UI, Artisan. |

Theme preset until an Instituta brand exists: **Teal** (`@qiick/theme`).

## 3. Ten tenancy cases

Three levels from day one: **organisation → companies/clients → products**. Queries are always scoped. Resolution default: **product > company/client > organisation**, every value with its origin.

| # | Case | Rule | MVP |
|---|---|---|---|
| 1 | Company, one product | Organisation + 1 product; org layer may be empty. Degenerate of case 2. | Yes |
| 2 | Company, n related products | Org-level brand/rules inherited; product overrides win; show origin. | Yes (core) |
| 3 | Company, n unrelated products | Inherit off per product (`inherits_from_parent = false`); grouping only explicit. | Yes |
| 4 | Agency, n clients × n products | Model in MVP; agency-portal UI phase 2. | Model yes, portal no |
| 5 | Same person/AI across worlds | Session and key bound to **one** scope. | Yes |
| 6 | Platform pieces owned by no product | Org-level **shared sources**; products reference by **id + version**, never copy. | Yes |
| 7 | Public vs internal in one product | Both live in one product; classification per item. | Yes |
| 8 | Ownership and exit | Client subtree exportable. | Yes (export) |
| 9 | qiick Cloud | Each paying customer is an isolated installation. Schema must not block. | Schema only |
| 10 | In-company authorization | Same ACL on UI, MCP, REST, search, embeddings, agent context, handoff, replay. | Model + filters; dogfood = single owner; no permission UI |

Out of MVP (schema must not block): teams inside a product, mergers, per-country residency.

## 4. What the app does in the MVP

**In**

- Data model from day one (ULIDs, scope columns on content rows): organisations, companies (`own`|`client`), products, product groups, principals, grants, API keys, sources / versions / references, items / sections / structured tables / links, search drivers, vault versions, freezes, agent sessions, contract call audit, quotas (schema), offboarding (export in MVP).
- Brain module + Artisan reader CLI + stdio MCP over a local folder.
- Read-only ingest (dogfood default: `local` provider on maintainer clones of knowledge + app-ui; site-ui skipped until it exists). Never-ingest denylist + minimal secret/PII scanner.
- MCP (HTTP) and REST implementing `docs/CONTRACTS.md`.
- Search: Postgres full-text (reference) + SQLite FTS5. No MySQL search driver in v0.1.0 (clear error).
- Local Fortify auth; keys via Artisan; one owner key in dogfood.
- UI: scope switcher, map, tree by meaning, reading view (compose from existing `@qiick` items; that PR waits for OK), structured tables, resolved view + "what the agent would see", simple scoped graph (**graph library not approved yet — leave last / skip if blocked**).
- Export of a client subtree; freeze/unfreeze Artisan; install/upgrade; `/health/live` and `/health/ready`.

**Out** (do not build; hooks only where the data model says so)

Writes/proposals, agency portal, permission UI, key-management UI beyond a read-only list, connected-systems registry / Externa plugin, per-vendor plugins, marketplace listings, embeddings computation, unscoped chat, client Slack ingest, qiick Cloud, Signaculum SSO, and `[plan]` features from the design note (brain eval, contradiction detection, temporal view, AGENTS.md generation, gaps queue, what-if, executable rules, voice lint, session replay UI, …).

## 5. Security

- Security is core: scope isolation is a tested requirement.
- One ACL everywhere via `PerimeterData`.
- Never ingest Klover, Cybergrant, Zerotredici.
- Never open those trees as fixtures or references.
- Audit rows never store content or secrets.

## 6. Quality and delivery

- English in the repo; Conventional Commits; one issue → one branch → one PR into `develop`.
- Stack and UI from qiick standards (app-ui ≥ v0.6.0); Vite+/Oxlint/Oxfmt; no ESLint/Prettier.
- Local site: `https://instituta-core.test` (Herd).
- Releases, tags, Packagist, GHCR, and public visibility only when the maintainer explicitly says so.

## 7. Document map

| Doc | Role |
|---|---|
| `docs/SPEC.md` | This file — product scope and tenancy |
| `docs/FORMAT.md` | Brain repository format (draft until approved) |
| `docs/CONTRACTS.md` | MCP + REST + Artisan contract (draft until approved) |
| `docs/agents/cursor-kickoff.md` | Build order and binding agent instructions |
