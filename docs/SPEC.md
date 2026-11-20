# Instituta — Specification (v1.0.0-beta.1)

> Status: refreshed 2026-11-17 to what has shipped on `develop` towards `v1.0.0-beta.1` (the kickoff decisions of 2026-11-12 still hold). `FORMAT.md` and `CONTRACTS.md` are the approved working contract (see those files for the freeze rule at `v1.0.0-beta.1`). Anything marked `[plan]` in the design note is direction only and is out unless listed below.

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
| Brain module | `app/Brain`, `tests/Unit/Brain` | Format, adapters, reader, resolver, perimeter filter as pure functions, contract DTOs, denylist, secret/PII scanner. No Eloquent, no app facades, no `config()` / `env()`. |
| App | rest of `app/` | Tenancy, principals, keys, grants, sources, index, ingest jobs, search drivers, MCP/REST/UI, Artisan. |

Theme: Instituta's own **Salvia** preset (knowledge `projects/qiick/instituta-brand.md`), on the `@qiick` theme tokens.

## 3. Ten tenancy cases

Three levels from day one: **organisation → companies/clients → products**. Queries are always scoped. Resolution default: **product > company/client > organisation**, every value with its origin.

| # | Case | Rule | MVP |
|---|---|---|---|
| 1 | Company, one product | Organisation + 1 product; org layer may be empty. Degenerate of case 2. | Yes |
| 2 | Company, n related products | Org-level brand/rules inherited; product overrides win; show origin. | Yes (core) |
| 3 | Company, n unrelated products | Inherit off per product (`inherits_from_parent = false`); grouping only explicit. | Yes |
| 4 | Agency, n clients × n products | Clients list, scope switcher, client and product pages, client export and offboarding erasure. | Yes |
| 5 | Same person/AI across worlds | Session and key bound to **one** scope. | Yes |
| 6 | Platform pieces owned by no product | Org-level **shared sources**; products reference by **id + version**, never copy. | Yes |
| 7 | Public vs internal in one product | Both live in one product; classification per item. | Yes |
| 8 | Ownership and exit | Client subtree exportable. | Yes (export) |
| 9 | qiick Cloud | Each paying customer is an isolated installation. Schema must not block. | Schema only |
| 10 | In-company authorization | Same ACL on UI, MCP, REST, search, embeddings, agent context, handoff, replay. | Yes: roles and permissions, user groups with brain access per group |

Out of MVP (schema must not block): teams inside a product, mergers, per-country residency.

## 4. What the app does

**In**

- Data model (UUIDs, scope columns on content rows): organisations, companies (`own`|`client`), products, product groups, principals, grants, API keys, sources / versions / references, items / sections / structured tables / links, search drivers, vault versions, freezes, agent sessions, contract call audit, quotas, proposals, gap reports, offboarding.
- Brain module + Artisan reader CLI + stdio MCP over a local folder.
- Read-only ingest from `local`, `git` and `github` sources, on a schedule or a push webhook. Never-ingest denylist and a secret/PII scanner that fails closed. Images, PDFs, archives, fonts and Office files enter as **assets** with metadata only; the text of PDFs and Office files is read locally into sections (`FORMAT.md` §7). Brain health after each sync.
- MCP (HTTP) and REST implementing `CONTRACTS.md`: search, items, outlines, sections (single and batch), tables, neighbours, resolved product view, context packs, changes since, gap reports, brain health; token estimates and stable error codes.
- **Writes as proposals:** agents and people propose changes (kill switches off by default); people accept or reject them in the review inbox; accepted proposals become a branch and a pull request on the source, never a direct write.
- Search: Postgres full-text (reference) + SQLite FTS5; optional hybrid search with embeddings (`AI_EMBEDDINGS_ENABLED`, off by default). No MySQL search driver (clear error).
- Fortify auth; API keys from Settings and Artisan; roles and permissions; user groups with brain access.
- UI: Explorer (files of every source, reading view, assets, mini-graph), whole-brain graph, resolved view ("what the agent sees"), review inbox (proposals and gaps), activity with agent calls and session replay, notifications, ⌘K command palette, optional in-app assistant (`AI_ASSISTANT_ENABLED`, off by default).
- **Agency portal:** clients and products, scope switcher, client subtree export, offboarding erasure, per-tenant monthly quotas.
- Freeze/unfreeze; install/upgrade; `/health/live` and `/health/ready`.

**Out** (do not build; hooks only where the data model says so)

Connected-systems registry / Externa plugin, per-vendor plugins, marketplace listings, unscoped chat, client Slack ingest, qiick Cloud, Signaculum SSO, uploading files through the app, OCR of scanned documents, and the remaining `[plan]` features of the design note (brain eval, contradiction detection beyond agent reports, temporal view, AGENTS.md generation, what-if, executable rules, voice lint, …).

## 5. Security

- Security is core: scope isolation is a tested requirement.
- One ACL everywhere via `PerimeterData`.
- Configurable never-ingest denylist (`INSTITUTA_INGEST_DENYLIST`); default empty.
- Never open denylisted trees as fixtures or references.
- Audit rows never store content or secrets.

## 6. Quality and delivery

- English in the repo; Conventional Commits; one issue → one branch → one PR into `develop`.
- Stack and UI from qiick standards (app-ui v0.9.0); Vite+/Oxlint/Oxfmt; no ESLint/Prettier.
- Local site: `https://instituta-core.test` (any local PHP environment: Flock, Herd or Valet). `php artisan db:seed --class=DemoAgencySeeder` on an empty database gives a fictional agency for demos.
- Releases, tags, Packagist, GHCR, and public visibility only when the maintainer explicitly says so.

## 7. Document map

| Doc | Role |
|---|---|
| `docs/SPEC.md` | This file — product scope and tenancy |
| `docs/FORMAT.md` | Brain repository format (draft until approved) |
| `docs/CONTRACTS.md` | MCP + REST + Artisan contract (draft until approved) |
| `docs/agents/cursor-kickoff.md` | Build order and binding agent instructions |
| `SECURITY.md` | Supported versions, vulnerability reporting, what leaves the server |
