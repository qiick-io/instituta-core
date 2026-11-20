# Cursor kickoff: Instituta MVP (2026-11-12)

Paste this whole file into Cursor as the first message of the session that builds Instituta. It is self-contained: it summarises every decision the maintainer took, the qiick rules, and the order of work. Where it points to a file, read the file; where this prompt and a binding file disagree, the order is: the repo's synced `AGENTS.md` block > the design note > this prompt.

You work on one **private** repository of the qiick organisation: **`qiick-io/instituta-core`** (this repo; Composer name `qiick/instituta-core`, `"type": "project"`). It is the **whole product**, the same way `externa-core` is for Externa: one self-hosted Laravel app with the MCP server, the REST API, the Artisan CLI and the UI.

> **One repo, not two (decision of 2026-11-12, Luca).** The design note and the Excubia-style split it recommends (`qiick/instituta-core` package + `qiick-io/instituta-app`) are **superseded** on this point: there is no `instituta-app` and no separate core package. The brain format reader and resolver live inside this app as a self-contained module (section 2.1) so they could be extracted later. Any extra piece added later gets a name that says what it is (`instituta-cli`, `instituta-sdk-php`), never a second "core". Where the note or an older link says "instituta-app" or "the core package", read "this repo" and "the brain module".

The maintainer is **Luca** (GitHub `LucaGiardi20`). Talk to him **in Italian**. Everything you write into a repo (code, comments, docs, commit messages, PR titles and bodies, issues) is **English**. Another agent will re-check your work, so leave a clean trail: small PRs, precise PR bodies, nothing hidden.

> **Working name.** "Instituta" is a working name pending an attorney (**Cogitata** is the reserve). The repo stays **private**. Do not register, buy, file, publish or post anything under the name: no Packagist package, no GHCR image, no domain, no npm package, no public repo, no social or marketplace listing. Keep the name in few places (config `app.name`, the `instituta:` Artisan namespace, the locale files), so a rename is a small PR.

### Decisions already taken (2026-11-12)

Luca answered these; do not ask them again. "Default" rows are choices made for you so you never block on them; follow them, say in the PR body that you did, and Luca can still change them later.

| Topic | Decision |
|---|---|
| Repositories | **One repo**, `qiick-io/instituta-core` (see the box above). There is no `instituta-app`. |
| Theme preset | **Teal** (`"uiPreset": "Teal"`, `@qiick/theme`), the `docs/new-product.md` default, until an Instituta preset exists in app-ui. Tokens only, so the later switch is one PR (install `@qiick/theme-<colour>`, change `uiPreset`). |
| MySQL | **No MySQL search driver in v0.1.0.** The app runs and is tested on MySQL 8 (CI job), but search on MySQL answers with a clear "search driver not supported" error (HTTP 501 on REST, a tool error on MCP, an empty state with an explanation in the UI). Document it in the README and open an issue for a FULLTEXT driver in the `Later` milestone. Search drivers: `pgsql` (reference) and `sqlite` (FTS5). |
| GitHub project | **Instituta**, private org project **#4** (`gh project … 4 --owner qiick-io`), linked to the repo, same fields as the Excubia board. Every issue and PR goes on it. |
| Merge autonomy | **Like Excubia.** PR 1 (bootstrap) and PR 2 (standards + CI) wait for Luca's OK. From PR 3 on, merge your own **green** PRs into `develop` (squash) without asking and report afterwards. Always ask first for: a new Composer or npm dependency, any change to `docs/FORMAT.md` / `docs/CONTRACTS.md` (PR 3 itself waits for his review), anything marked `[plan]`, the reading view's layout (below), releases, tags and anything public. The repo has no branch protection (free plan), so "green" means every CI job green, by your own discipline. |
| Resolution order (default) | **product > company / client > organisation**, every value with its origin. In case 2 the company layer is usually empty, so this equals the note's product > org. |
| Dogfood sources (default) | Use the `local` provider on the maintainer's existing clones for dogfood: `LucaGiardi20/knowledge` (ask Luca for the folder once) and `../../app-ui`. No GitHub token is needed for the MVP; the `github` provider is built and tested with `Process::fake()` / `Http::fake()`, and a read-only token is added to `.env` only when Luca wants remote sync. **site-ui is skipped** until `qiick-io/site-ui` exists; adding it later is one `instituta:source:add`. |
| Reading view (default) | app-ui has no long-form reading pattern. Compose the reading view **only from existing `@qiick` items** (`page-layout`, `page-header`, `scroll-area`, `sources` / `marker` for citation chips, `table`, `data-table`, `code-block`, `collapsible`, `aspect-ratio`, `carousel` for the image gallery) with a sticky outline, no new visual primitives, and record it in `app-ui.overrides.json` with the reason "no app-ui reading pattern yet; proposed upstream". That PR waits for Luca's OK before merge, and its body proposes the pattern for app-ui. |
| Still a question | A **graph library** (new dependency): propose one with size, licence and maintenance, and wait. |

---

## 0. Read these first, in this order

Do not write code before you have read them.

1. **The design note (source of truth for the product):** `projects/qiick/research/2026-11-12-instituta-company-brain.md` in the private knowledge repo `LucaGiardi20/knowledge`. Read it in full (`gh api repos/LucaGiardi20/knowledge/contents/projects/qiick/research/2026-11-12-instituta-company-brain.md -q .content | base64 -d`, or the maintainer's local clone). Then the notes it links:
   - `projects/qiick/research/2026-10-27-ai-knowledge-vault-idea.md` (progressive disclosure, sidecars, graph from the filesystem; read the "Superseded 2026-11-12" lines);
   - `decisions/2026-11-01-excubia-core-and-app-split.md` (the Excubia split; for Instituta it is **not** copied, see the box above);
   - `projects/qiick/research/2026-11-05-signaculum-sso-design.md` (local Fortify login now, SSO later through `qiick/laravel-oidc`);
   - `projects/qiick/research/2026-11-11-short-product-names.md`, `decisions/2026-11-11-site-ui-for-websites.md`, `conventions/app-ui.md`;
   - `decisions/2026-11-10-merge-autonomy-and-branch-protection.md`, `conventions/github-repo-settings.md`, `decisions/2026-11-10-local-environments.md`, `decisions/2026-11-09-vite-plus-toolchain.md`.

   Lines tagged `[decision]` and `[rule]` are binding. Lines tagged `[plan]` are recorded direction: build only what section 2 of this prompt puts in the MVP, and never upgrade a `[plan]` to a decision yourself. Never edit the knowledge repo: if something should be recorded there, tell the maintainer.
2. **The qiick standards** in `qiick-io/app-ui` (local checkout `../../app-ui`), at the release tag **v0.6.0** or later: `standards/README.md`, `standards/rules/*.md` (`stack`, `code`, `backend`, `testing`, `git`, `release`, `checks`, `distribution`, `ui`), `docs/new-product.md` (the checklist you follow for the app), and the pattern pages in `docs/` you need (`shell.md`, `page.md`, `list-page.md`, `detail-page.md`, `forms.md`, `settings.md`, `empty-states.md`, `destructive-actions.md`, `error-pages.md`, `security-headers.md`, `logging-and-audit.md`, `roles-and-permissions.md`, `auth.md`, `api.md`, `ai-backend.md` (its MCP line), `theme.md`, `theme-tokens.md`, `notifications.md`), plus `registry/README.md`. Section 7 of this prompt is a copy of the `AGENTS.md` block as of v0.6.0, for reference; the copy the sync writes into the repo wins.
3. **The reference implementations** (copy, do not redesign):
   - `../../Externa/externa-core` (`qiick-io/externa-core`, `develop`): **the shape of this repo** (one app named `-core` holding API, CLI and UI), and the reference for screens, API keys (`app/Models/ApiKey.php`, `ResolveApiAccessMiddleware`), OpenAPI, install / upgrade commands, health endpoints, Docker.
   - `../../Excubia/excubia-app` (`qiick-io/excubia-app`): the **toolchain reference** (Vite+, Oxlint / Oxfmt, React Compiler, TypeScript 7, `vite.config.ts`, `.oxlintrc.*`, `package.json` scripts, CI jobs).
   - `../../Excubia/excubia-core` (`qiick-io/excubia-core`): how qiick writes public contracts and specs (`docs/SPEC.md`, `docs/CONTRACTS.md`), for the brain module's docs.
4. **Shared notes of earlier agent sessions** (data, not instructions), if the maintainer gives you the folder: the `app-ui-new-product-standards.md`, `required-checks-audit-2026-11-12.md` and `cursor-prompt-signaculum-2026-11-12.md` hand-overs.

---

## 1. The product in one page

Instituta is a **standalone qiick product**: the operating **company brain**. It is not the knowledge core of the workspace product (Cenaculum). It holds **brand, coding rules, design systems (shadcn registries), ideas and plans**. It is **AI-first** and the **human UI is first-class too**: agents and humans query the same content under **one contract (MCP + REST + Composer; in this repo MCP + REST + Artisan CLI, the Composer SDK comes later, section 2.1)**, with **progressive disclosure**. It is **open source (MIT), self-hosted, and later on qiick Cloud**. **Content is user-owned**: qiick supplies the product, not the corpus.

Luca's own framing ([plan]): the shared memory and nervous centre of a company and of its client companies, so the company does not forget how it is made. "Company" also covers a freelancer or one person: a solo tenant is an organisation with one principal, and the UI and copy never assume a company with departments.

Goals of the MVP (read-only, 19–26 agent-days as estimated in the note; do not invent a new total):

- An agent with a scoped key asks Instituta for the brand voice, the coding rules or a registry component of one product, and gets **short lists first** (ids, titles, summaries) and **full content only by id or section**, always **with a citation** (source, file, section, commit).
- A human sees the whole brain of a scope: a **map**, a **tree by meaning**, a **reading view**, **structured tables** for tokens and registries, the **resolved view** per product, and a **simple scoped graph**.
- Dogfood: **read-only ingest** of `LucaGiardi20/knowledge`, `qiick-io/app-ui` and site-ui, for **one company with many products** (Luca's case), on a data model that is **multi-tenant from day one**.
- **Security is core** (Luca: "la sicurezza è a dir poco core"): scope isolation is a tested security requirement, not a convention.

Fixed principles:

- **Knowledge and policy plane, not a data-plane proxy** ([decision]). Instituta never proxies writes to other qiick products and never holds their keys. An agent that must update an Externa article makes two authenticated calls: Instituta (key A) for style and rules, then Externa (key B).
- **The Externa beta must not depend on Instituta.** Nothing in Externa or Excubia changes for this MVP.
- **MCP is the primary door** for agents; REST and the CLI for non-MCP clients; Composer for Laravel apps. Same contract, same identity. No per-vendor plugins, no marketplace listings.
- **Git is the durable store**; the graph is **derived from the filesystem**. GitHub is the first connector; any git remote works. MVP sync is **read-only**; writes as pull requests come after the MVP. (The git-native architecture is a recommendation awaiting Luca's formal OK: build the read-only ingest on it, and ask before anything that would make it hard to change.)
- **Not a Gantt.** Knowledge items carry three separate dates (decision, effective, review / expiry), never one due date; no tickets, assignees or progress bars.

### 1.1 The ten tenancy cases

Three levels from day one: **organisation → companies / clients → products**. Queries are always scoped.

| # | Case | Rule | MVP |
|---|---|---|---|
| 1 | Company, one product | Organisation + 1 product; the org layer can be empty. Degenerate of case 2. | **Yes** |
| 2 | Company, n related products (qiick today) | Org-level brand and rules are inherited; product overrides win. Resolution **product > org**; every resolved value shows its **origin**. | **Yes (core)** |
| 3 | Company, n unrelated products | Default isolation: inherit is **off per product**; grouping only explicit. | **Yes** (flag + explicit group) |
| 4 | Agency, n clients × n products | The model is in the MVP; the agency-portal UI is phase 2. | **Model yes, portal no** |
| 5 | Same person or AI across worlds | A session (and a key) is bound to **one scope**. | **Yes** |
| 6 | Platform pieces owned by no product (Tabula, app-ui, a shared design system) | Live at org level as **shared sources**; products reference them **by id + version**, never by copy. | **Yes** |
| 7 | Public vs internal in the same product | Both live in one product; classification per item. | **Yes** |
| 8 | Ownership and exit | A **client subtree is exportable**. | **Yes** (export) |
| 9 | qiick Cloud | Each paying customer is an **isolated installation** (not cases 1–4). The schema must not block it. | **Schema only** |
| 10 | In-company authorization | "This person, or this agent, may read this subtree." Same ACL on **UI, MCP, REST, search, embeddings, agent context, handoff and replay**. | **In the model + query filters; dogfood = single owner; no permission UI** |

Out of the MVP, and the schema must not block them: teams inside a product (case 10's hook), mergers, per-country data residency.

Resolution with three levels: the note fixes **product > org**. With a company / client level in between, resolve **product > company / client > organisation**, where the company level is usually empty in case 2. This is the default Luca accepted on 2026-11-12 (see "Decisions already taken").

---

## 2. What goes where, and what is in the MVP

### 2.1 One app, with a self-contained brain module

Everything is in this Laravel app. Inside it, keep two layers apart:

| Layer | Where | What |
|---|---|---|
| **Brain module** | `app/Brain/` (namespace `App\Brain`), tests in `tests/Unit/Brain/` | The brain repository **format** (`docs/FORMAT.md`): frontmatter schema, item types and areas, typed blocks, sidecars. The **reader** and its source **adapters** (native format, Basic Memory markdown, shadcn `registry.json`, markdown docs): sections, deterministic summaries, links → graph edges, citations. The **resolver**: scopes, inheritance with origins, inherit flag, shared sources by id + version. The **perimeter / ACL filter** as a pure function. The **contract** DTOs (`docs/CONTRACTS.md`). The never-ingest denylist and the secret / PII scanner. |
| **App** | the rest of `app/` | Tenancy, users, principals, keys, grants, sources, index tables and migrations, ingest jobs, search drivers, MCP (HTTP and stdio), REST, Artisan commands, Inertia UI. It implements the contract on the database and calls the brain module during ingest and resolution. |

Rules for the brain module, enforced by an arch test (`tests/Arch/BrainModuleArchitectureTest.php`):

- It depends on nothing else in the app: no `App\Models`, `App\Http`, `App\Jobs`, `App\Services` outside `App\Brain`, no Eloquent, no facades, no `config()` / `app()` / `env()`. Plain PHP plus the few libraries it needs (a YAML and a CommonMark parser), with inputs passed in as `*Data` objects. The app may use the module; the module never uses the app.
- It works on a **local folder**, so `php artisan instituta:read --path=<repo> list|search|get|outline|resolve|graph|validate` and the stdio MCP server (`php artisan instituta:mcp --path=<repo>`) can read a compatible git repository **without the database index**: this keeps the note's "reads a compatible repo without the app" as a capability of the CLI.
- Its own tests use small synthetic fixture repositories under `tests/Unit/Brain/Fixtures`.
- Extraction later (a package with a name that says what it is) should be a namespace move, not a rewrite. Do not extract it now.

The **Composer** leg of the one contract (a client for other Laravel apps) is not in this repo: it becomes a separate later repo with a descriptive name (for example `qiick-io/instituta-sdk-php`), only when Luca says so. In the MVP the contract ships as MCP + REST + Artisan CLI, and `docs/CONTRACTS.md` is written so the SDK can follow it later.

### 2.2 MVP scope

**In:**

- Data model from day one (section 3), with migrations, factories and isolation tests.
- The brain module: format + reader + resolver + contract, plus the Artisan reader CLI and the stdio MCP server over a local folder.
- Read-only ingest of the three dogfood sources, with the never-ingest guard and a minimal secret / PII scanner.
- MCP (HTTP) and REST over the same contract. (The Composer SDK is a later, separate repo: section 2.1.)
- Search: Postgres full-text (reference), SQLite FTS5 so the default install and the tests work (section 3.4).
- Local auth (Fortify, from the starter kit), principals, API keys bound to principal + perimeter + role, created and revoked from Artisan; one owner key in dogfood.
- UI: scope switcher, map, tree by meaning, reading view, structured tables (at least tokens and registries), resolved view with the "what the agent would see" toggle, simple scoped graph.
- Export of a client subtree (case 8). Freeze of context injection and rollback to a signed vault version as an Artisan command (schema + command; no UI).
- Install / upgrade commands and health endpoints (distribution rules).

**Out (do not build; schema hooks only where section 3 says so):** writes and proposals (+4–6 days, next), the agency portal (+8–12), a permission UI, a key-management UI beyond what section 5 lists, the connected-systems registry and the Externa plugin, per-vendor plugins and marketplace listings, embeddings computation (schema only), unscoped chat, ingest from clients' Slack, qiick Cloud, Signaculum SSO, the further UI cases (compare scopes, brief view, what-changed briefing, contradiction resolution UI, visual what-if, assembled search views), and the near-MVP [plan] features of the note (brain eval, contradiction detection, temporal view, resolved agent context / `AGENTS.md` generation, gaps queue, what-if, executable rules, voice lint, session replay UI, …). They are good ideas Luca accepted as direction; each needs its own issue and his OK on scope.

---

## 3. Data model and storage (day one)

Everything below is in the schema from the first migration PR, even where the MVP has no UI for it. Use ULIDs (`HasUlids`) for every shared record, so ids stay unique across installations (case 9) and exports (case 8). Every row that holds brain content carries its scope columns (`organisation_id`, `company_id`, `product_id`) so isolation is a `WHERE`, not a join you can forget.

### 3.1 Tenancy

- `organisations` (`slug`, `name`). A solo person or freelancer is an organisation with one principal.
- `companies` (`organisation_id`, `slug`, `name`, `kind`: `own` | `client`). Every organisation gets one `own` company at install. Clients are companies of kind `client` (case 4).
- `products` (`company_id`, `slug`, `name`, `inherits_from_parent` boolean, default `true`; `false` = case 3 isolation).
- `product_groups` + `product_group_product` (case 3: grouping only explicit).
- Not now, not blocked: teams inside a product, mergers, data residency (no column may assume one region or one company per product forever).

### 3.2 Principals, keys and the ACL (case 10)

- `users` (Fortify, from the starter kit) ↔ `principals` (`organisation_id`, `type`: `person` | `agent` | `service`, `user_id` nullable, `owner_principal_id` for an agent or service that acts for a person, `name`, `expires_at` for time-boxed access, `deactivated_at`). When a person's principal is deactivated or expires, **every key and agent principal they own stops working** (test it).
- `grants` (`principal_id`, `scope_type` organisation | company | product, `scope_id`, `role`: `owner` | `member` | `guest`, `max_visibility`: `public` | `internal` | `restricted`, optional `path_prefix` / `area` to narrow to a subtree, `expires_at`). Owner on the organisation sees everything; member on an assigned org or client; guest only with explicit grants.
- `api_keys` (as externa-core: `<prefix>_` + 40 random characters shown once, SHA-256 hash, 12-character display prefix; `principal_id`, perimeter `scope_type` + `scope_id`, `role` and `max_visibility` that can only be **narrower** than the principal's grants, `abilities` (`brain:read` in the MVP; `brain:write` exists in the enum but is refused), optional IP allowlist, `expires_at`, `last_used_at`, `revoked_at`). **Key + principal + scope + role**: not enterprise IAM.
- Visibility levels per item: `public`, `internal`, `restricted` (case 7 plus case 10's extra level).
- **One ACL, everywhere:** a single `PerimeterData` (principal, scope, role, max visibility, subtree) is resolved once per request (session for the UI, key for MCP / REST) and every query goes through one query scope / service that applies it: lists, search, get, sections, graph, resolved view, export, embeddings (when they exist) and the audit / replay log. An agent never sees more than its principal. Owner sees the whole brain; anyone else sees a clearly labelled partial view.

### 3.3 Sources, items and the graph

- `sources` (scope columns + `scope_type`, `name`, `provider`: `github` | `git` | `local`, `remote_url` or local path, `ref`, `path` inside the repo, `adapter`: `instituta` | `basic-memory` | `shadcn-registry` | `markdown`, `default_visibility`, `is_shared` (case 6: org-level shared source), `last_synced_commit`, `sync_status`, `last_synced_at`).
- `source_versions` (`source_id`, `label` (a tag such as `v0.6.0`), `commit`): what a product can pin.
- `source_references` (`product_id`, `source_id`, `source_version_id` or a pinned commit): case 6, **by id + version, no copy**.
- `items` (scope columns, `source_id`, `path`, `stable_key` (frontmatter `permalink` / `key`, else the path without extension), `type` (note, decision, rule, convention, brand, glossary, plan, idea, component, token-set, …), `area` (rules, decisions, style / brand, glossary, design system, plans, connected systems, notes), `title`, `summary` (frontmatter `summary`, else the first paragraph cut at a fixed length: deterministic, no AI), `visibility`, `commit`, `content_hash`, `frontmatter` JSON, `decision_date`, `effective_date`, `review_date` (three separate dates), `resolution_key` (the key that product > org overrides match on, for example `brand.voice`)).
- `item_sections` (`item_id`, `anchor` (slugified heading path), `heading`, `level`, `position`, `body`, `blocks` JSON with typed blocks: `text`, `image` (path, **mandatory alt**, caption; agents get path + alt + caption, never the binary), `table` (narrative), `data-table` (reference to structured rows), `code`).
- `structured_tables` + `structured_rows` (`item_id`, `table_key`, `columns` JSON, `values` JSON, position): source-of-truth tables (colour tokens, app-ui and site-ui registries, lexicon) as **real rows** an agent can query, never scraped markdown.
- `item_links` (`from_item_id`, `to_item_id` nullable, `target` (unresolved `[[wikilink]]` or path), `relation` (the Basic Memory relation verb, `links_to` for plain links)): the graph, **derived from the files** at each sync.
- Binaries: an image or PDF is indexed through its **sidecar** (caption, extracted text, tags), never as raw content.

### 3.4 Search

- Stack default is **SQLite** (install, tests, CI) with MySQL 8 and PostgreSQL 17 supported (`standards/rules/stack.md`); the note fixes **Postgres full-text search**. Reconciled: one `SearchIndexContract` with a **`pgsql` driver** (a `tsvector` column with a GIN index on `item_sections`, `websearch_to_tsquery`, `ts_rank`, `ts_headline` for short snippets; the reference driver for production and dogfood) and a **`sqlite` driver** (FTS5 virtual table) so the default install and the test suite work. Vendor SQL only inside the driver, behind a driver check. **MySQL:** no search driver in v0.1.0 (see "Decisions already taken"): a clear "not supported" error, documented, with an issue in `Later`.
- Every search runs **inside the perimeter first** (scope columns + visibility + subtree), then ranks. Results return ids, titles, summaries, a short snippet and the citation, never full bodies.
- Embeddings: **schema only** in the MVP: `embedding_models` (`name`, `version`, `dimensions`) and on each indexed section `embedding_model_id` + `embedded_at` (nullable). Vectors, when they come, carry the scope columns (embedding isolation) and are re-indexed when the model version changes.

### 3.5 Operations and security hooks (schema in the MVP)

- `vault_versions` (`organisation_id`, `label` `instituta@vN`, per-source commits JSON, `created_at`, `signature` nullable): what a release or an agent session pins.
- `context_freezes` (scope columns, `vault_version_id` to roll back to, `reason`, `frozen_by_principal_id`, `frozen_at`, `lifted_at`): the emergency kill switch; while a freeze is active, every contract call in that scope serves the pinned version. `instituta:freeze` / `instituta:unfreeze` in the MVP.
- `agent_sessions` (`api_key_id`, scope, `vault_version_id`, `started_at`, `invalidated_at`): stale sessions are invalidated when the vault moves past the pinned version.
- `contract_calls` (audit / replay log: principal, key, session, tool or endpoint, scope, item ids returned, token estimate, timestamp; **never content or secrets**), with a retention setting.
- `quotas` + `quota_usages` (scope, metric, limit, period): per-tenant cost quotas; schema only, plus a simple per-key rate limiter (`api`: 60/min, as externa-core).
- `offboarding_requests` (`company_id`, `status`, `export_path`, `requested_at`, `erased_at`): export, deletion including embeddings and replay, key revocation. Schema + the export command in the MVP; erasure flow later.
- `connected_systems` (`product_id`, `system`, `base_url`): schema may wait; never build a proxy on it.

### 3.6 Never ingest

**A configurable never-ingest denylist (`INSTITUTA_INGEST_DENYLIST`) refuses matching organisations.** Enforce it in code, not only in docs: `config/instituta.php` `ingest.denylist` (comma-separated env, trimmed and lowercased, default empty; matched case-insensitively on repository owner / name, remote URL and path) that **refuses** to create or sync a source, in the brain module's reader too, with tests. Installs that must refuse named organisations set the env; do not hardcode real names in the repo. You yourself never open, clone, read or copy anything from denylisted projects, not even as fixtures.

Ingest hygiene ([plan], minimal in the MVP): a secret / PII scanner on ingest (private keys, API tokens, `.env` content, passwords in URLs, e-mail addresses and phone numbers in bodies) that skips the file, records why in the sync report, and never stores the match. Add a rule list of what must not enter (chat dumps, generated files, `vendor/`, `node_modules/`, binaries without a sidecar).

---

## 4. The one contract (MCP + REST now, Composer SDK later)

Write it first in `docs/CONTRACTS.md` (names, inputs, outputs, errors, limits) and get Luca's OK on that PR before implementing it: it is a public contract like `excubia-core`'s. Everything is scoped by the caller's perimeter; nothing takes a scope wider than the key.

| Operation | MCP tool | REST (`/api/v1`) | Returns |
|---|---|---|---|
| Who am I, which scopes | `instituta_scopes` | `GET /scopes` | principal, perimeter, visible scopes |
| List | `instituta_list` | `GET /items?scope=&area=&type=&cursor=` | ids, titles, summaries, area, type, visibility, origin; cursor-paginated |
| Search | `instituta_search` | `GET /search?scope=&q=&area=&limit=` | ids, titles, summaries, short snippets, score, citation |
| Outline | `instituta_outline` | `GET /items/{id}/outline` | section anchors and headings, block types, no bodies |
| Get by id | `instituta_get` | `GET /items/{id}` | full content as typed blocks + citation |
| Get by section | `instituta_get_section` | `GET /items/{id}/sections/{anchor}` | that section only + citation |
| Table rows | `instituta_table` | `GET /items/{id}/tables/{key}?filter=` | structured rows |
| Resolved view | `instituta_resolve` | `GET /products/{id}/resolved?area=` | the resolved keys for a product, each with its **origin** (scope, source, file, commit), ACL cuts and a token estimate |
| Graph | `instituta_graph` | `GET /items/{id}/neighbours?depth=1` | nodes (id, title, area) and edges (relation) inside the perimeter |

Rules:

- **Progressive disclosure:** list / search / outline never return bodies. Content only from get, get-section and table.
- **Mandatory citations:** every piece of content carries `{source, path, section, commit}`.
- **Same identity:** MCP and REST use the same API keys (`Authorization: Bearer …`), abilities `brain:read` (scope name aligned with app-ui `ai-backend.md`: MCP off by default with `mcp.enabled`, writes separately off with `mcp.write_enabled`, which stays off).
- REST follows app-ui `docs/api.md`: thin controllers, `*Resource` classes, snake_case, ISO-8601 dates, errors as Laravel JSON, a hand-written OpenAPI 3.1 file at `resources/openapi/instituta-v1.yaml` served at `GET /api/v1/openapi.json`, with the route-coverage test.
- The same DTOs (from `App\Brain\Data`) back MCP, REST and the local reader CLI, so a later Composer SDK can return them unchanged.
- A frozen scope (3.5) answers from the pinned vault version; the response says so.

---

## 5. UI MVP (human surfaces)

Built with **app-ui** only: `@qiick` registry items → copy from excubia-app / externa-core `develop` → **stop and ask**. Never invent a layout, component, colour or position (`conventions/app-ui.md`). Three coordinated surfaces, always on **one scope** visible in the header (scope switcher: organisation → company / client → product):

1. **Map** ("the whole brain"): areas (rules, decisions, style, glossary, design system, plans, connected systems), coverage counts, empty areas shown as gaps, "you are here". Not all the text at once. The reserved Armarium mark may illustrate it later; not the product icon.
2. **Tree by meaning** (areas → types → items), not raw git paths, with a list view for each node.
3. **Reading view:** app-ui typography, sticky outline, citations as chips (source, file, section, commit; registry `sources` / `marker` items), images as first-class blocks (one canonical image, the rest in a gallery, alt mandatory), narrative tables with the app-ui `table` (sticky header, scroll), structured tables with `data-table`. **app-ui has no long-form reading pattern yet** (site-ui has one, but site-ui is for the websites only): compose it from existing `@qiick` items as described in "Decisions already taken", record the override, and wait for Luca's OK on that PR.
4. **Structured tables** for colour tokens and the app-ui / site-ui registries: a component shown with its preview where available, the tokens it uses, its rules and images, not raw `registry.json`.
5. **Resolved view** per product: what the agent actually receives, each value with its origin (inherited vs overridden), ACL cuts and the token estimate, plus a per-item toggle **"what the agent would see"**.
6. **Graph** (Graphify-like, simple, 2–3 days): always filtered by scope and grouped by area, starts from a selected node and shows neighbours (no hairball), click a node → reading view. No neural / brain clichés; not the home screen. A graph library is a new dependency: propose one and ask.

Plus: login and account pages from the starter kit and app-ui `auth.md` (Fortify, 2FA, passkeys; no public registration: the first owner comes from `instituta:install`), a minimal **Sources** page (list, sync status, last commit, last sync report) and a read-only **Keys** list for the owner (create / revoke stay in Artisan in the MVP; ask before building more). Every string through `t('…')` in `en`, `it`, `de` (German formal "Sie"); light, dark and high contrast at WCAG 2.2 AA; control height 32 px by default; tokens only; the violet `ai` tokens only for AI surfaces (for example the "what the agent would see" panel).

**Theme preset:** Instituta has no brand colour yet (brand, colour and logo are not started). Per `docs/new-product.md` and Luca's answer, start on **Teal** until the maintainer adds `@qiick/theme-<colour>` to app-ui; never edit surface tokens.

---

## 6. Build plan: small PRs, in this order

One issue → one branch → one PR, each with tests, the full quality gate green, and a PR body that says what, why, how it was verified and what is not done. Branch from `develop` as `<type>/<issue>-<kebab-slug>`; Conventional Commit PR titles; squash merge into `develop`. **Merges follow "Decisions already taken":** PR 1 and PR 2 wait for Luca's OK; from PR 3 on, merge your own green PRs into `develop` and report afterwards, except where that table says to ask. After each PR, report to him in Italian (section 11).

Local environment: Laravel Herd serves `https://instituta-core.test` (`herd link && herd secure`); use the Herd tools for dev processes, never your own server. Node 24 (`.nvmrc`; the default `node` in a shell may be older, so load Node 24 first). **One database per app:** SQLite `database/database.sqlite` by default and one Postgres database **`instituta_core`** for the search driver; for a clean state `php artisan migrate:fresh --seed` on it, never a copy. Remove any worktree, Herd site or extra database you create when its PR is merged or dropped, after checking for unpushed work.

| # | PR | Done when |
|---|---|---|
| 1 | `chore: bootstrap` from `laravel new instituta-core --react --pest --npm --database=sqlite --git` (official React starter kit: Inertia 3, React 19, TypeScript 7, Tailwind 4, shadcn, Fortify, Wayfinder, Vite+). `.nvmrc` 24 + `engines`, `composer.json` name `qiick/instituta-core` with `"type": "project"` and MIT, the stack packages of `docs/new-product.md` (`laravel/horizon`, `laravel/reverb`, `spatie/laravel-permission`, `spatie/laravel-activitylog`, dev `larastan/larastan`, `laravel/boost`), React Compiler as excubia-app (`@rolldown/plugin-babel` + `reactCompilerPreset()` in `vite.config.ts`), `react-i18next` with `en` / `it` / `de`, `Model::shouldBeStrict(! app()->isProduction())`. Keep the kit's Vite+ / Oxlint / Oxfmt setup; never ESLint or Prettier. | the app boots on `https://instituta-core.test`; `vp check`, `tsc --noEmit`, `php artisan test` green |
| 2 | `chore: adopt the qiick standards pack`: `node ../../app-ui/scripts/new-product.mjs --target . --name instituta --preset <answer from section 5>` at app-ui **v0.6.0** (or the latest tag): `qiick-standards.json`, `components.json` pinned to the tag, `.github/workflows/qiick.yml`, the standards sync. Fill `tests/Arch/naming-architecture.php` (incl. the brain module's roles, section 7); repo section below the block in `AGENTS.md`; `php artisan boost:install`; own CI workflow (section 8); PHPStan **level 8, no baseline**; `coverage-floor.txt` after the first green coverage run; theme + `app-shell` from the registry. No `dependabot.yml`. | CI green on the PR; drift check green; list for Luca of the checks he would require (by convention: private repos on the free plan cannot be protected) |
| 3 | `docs: brain format and contract`: `docs/FORMAT.md` (layout, `instituta.json` manifest, frontmatter fields, types, areas, visibility, the three dates, `resolution_key`, sidecars, typed blocks, how Basic Memory notes and shadcn registries map onto it), `docs/CONTRACTS.md` (section 4), `docs/SPEC.md` (the ten cases and what the app does for each, and the one-repo decision). **Stop for Luca's review**: this fixes the contract. | Luca approved the docs |
| 4 | `feat(brain): read a brain repository`: the brain module (`app/Brain`) with the native, `basic-memory` (frontmatter, `## Observations` with `- [category] text #tag`, `## Relations` with `- verb [[Target]]`), `shadcn-registry` (`registry.json` → structured rows) and `markdown` adapters (sections by heading, typed blocks, deterministic summaries, sidecars), links → edges, citations with commit; never-ingest denylist; secret / PII scanner; `BrainModuleArchitectureTest`. New libraries (YAML, CommonMark) listed in the PR for Luca's OK. Fixtures are small synthetic repos, never copies of private content. | Pest covers every adapter, the denylist and the scanner; the arch test proves the module uses nothing from the app |
| 5 | `feat(brain): scopes, inheritance and perimeter`: product > company / client > organisation with origins, `inherits_from_parent`, explicit groups, shared sources by id + version, visibility levels, `PerimeterData` filter (role, max visibility, subtree). Resolution order as decided (product > company / client > organisation). | tests for each of cases 1, 2, 3, 5, 6, 7, 10 at file level |
| 6 | `feat: local reader cli and stdio mcp server`: `php artisan instituta:read --path=<repo> list|search|get|outline|resolve|graph|validate` and `php artisan instituta:mcp --path=<repo>` (stdio, `laravel/mcp`, section 4 tools, single-owner perimeter), both on the brain module only, no database. | an MCP client (Cursor) can query a fixture repo locally; shared contract tests |
| 7 | `feat: tenancy, principals, keys and index schema`: every table of section 3 (forward-only migrations from now on), models with relations / casts / scopes only, factories with states, policies, `PerimeterData` resolution, the single query scope that applies it, `instituta:key:create|list|revoke`, expiry and deactivation cascades. **Isolation tests**: two organisations and two clients with overlapping slugs, each query path proves it sees only its own rows. | tests green incl. isolation; PHPStan 8; arch tests |
| 8 | `feat(ingest): read-only sync of git sources`: `instituta:source:add`, `instituta:sync` + a queued `SyncSourceJob` (git fetch into `storage/instituta/sources/<id>`, read-only, via the brain module's reader), sync report, `source_versions` from tags, `vault_versions` per sync, denylist enforced again, scanner. Sources for dogfood: the knowledge repo and app-ui (org-level shared source, case 6) through the `local` provider; site-ui skipped until its repo exists; a GitHub token only when Luca asks for remote sync, in `.env` only. | the dogfood sources sync on Luca's machine; `Process::fake()` in tests |
| 9 | `feat(mcp): scoped mcp server over http` with `laravel/mcp`, key auth, the section 4 tools on the database-backed implementation of the contract, `mcp.enabled` default off, audit rows in `contract_calls`, freeze honoured, rate limit. | the shared contract tests pass over HTTP MCP; a forbidden scope returns nothing, not an error leak |
| 10 | `feat(api): rest v1 with the same contract`, OpenAPI file + route-coverage test. | same contract tests over REST |
| 11 | `feat(search): postgres full-text and sqlite fts5 drivers`; CI job on PostgreSQL 17; on MySQL the "not supported" error, its test, the README line and the `Later` issue. | ranking + perimeter tests on both drivers |
| 12 | UI, one PR per screen: shell + scope switcher; map; tree + list; reading view (composed from existing items; waits for Luca's OK); structured tables; resolved view + "what the agent would see"; graph; sources page; keys list. | each with a Pest browser test (light and dark), axe smoke, `en` / `it` / `de` keys |
| 13 | `feat: auth and keys hardening`: no public registration, `instituta:install` creates the first organisation, own company, owner user, principal and owner key; key expiry and revocation shown read-only in the UI; activity log for keys and syncs. | tests for deactivation cascade and expired keys on UI, MCP and REST |
| 14 | `feat: export, freeze and distribution`: `instituta:export {company}` (the client subtree: its sources' commits, index JSON, a manifest; case 8), `instituta:freeze|unfreeze`, `instituta:install|upgrade`, `GET /health/live` and `/health/ready`. Dockerfile / GHCR / Packagist **only when Luca says so**. | tests; export of client A contains nothing of client B |
| 15 | `test: isolation, a11y and dogfood pass`: a cross-tenant suite over UI, MCP, REST, search, graph, resolved view and export; axe smoke; a dogfood report (what was ingested, what the scanner skipped, what agents can ask). | suite green; report to Luca |

Step 3 (format and contract) waits for Luca's review; do not build steps 4 and later on a contract he has not approved.

---

## 7. The qiick standards (`AGENTS.md` block, app-ui v0.6.0)

The block below is what `scripts/sync-standards.mjs` writes into `AGENTS.md` for this repo (`"uiPreset": "Teal"`, `"php": true`, `"oxlint": true`, `"app": true`). It is here so this prompt is complete; in the repo, the synced copy is the one that counts, and the details it points to are in `.qiick/standards/<page>.md` after the sync.

<details>
<summary>qiick standards block (app-ui v0.6.0)</summary>

### qiick standards

These rules bind every agent and contributor in this repo from the first task. They are the same in every qiick repo (source: https://github.com/qiick-io/app-ui/tree/develop/standards), and CI fails when this block differs from the source. Rules for this repo only live outside this block; where they are more specific, they win. Starting a new qiick product: https://github.com/qiick-io/app-ui/blob/develop/docs/new-product.md.

#### Stack

Every qiick app runs the same stack, so code, rules and registry items move between apps unchanged. A new app starts here; an existing app that differs converges on it, one PR per change. Steps from an empty repo to a green CI: https://github.com/qiick-io/app-ui/blob/develop/docs/new-product.md

##### Runtime defaults

- **Database:** SQLite by default (install, tests, CI); MySQL 8 and PostgreSQL 17 supported and tested in CI. No vendor-specific SQL outside a driver check.
- **Queue:** the `database` driver by default; **Horizon** (`laravel/horizon`) when Redis is configured. Slow work (mail, AI, imports, webhooks) is a queued `*Job`.
- **Realtime:** **Reverb** (`laravel/reverb`) with `laravel-echo` + `pusher-js`; broadcasting is optional and the UI works without it (realtime badge grey).
- **Cache / session:** `database` by default, Redis when configured.
- **Locales:** `en`, `it`, `de` from the first commit: every UI string through `t('…')`, every key in all three locale files.
- **Self-hosting:** an app is installed with `composer create-project` and `php artisan <app>:install`, updated with `<app>:upgrade` (see the distribution rules).

##### Frontend toolchain

**Vite+** (`vite-plus`: `vp dev`, `vp build`, `vp check` with Oxlint and Oxfmt), as the Laravel React starter kit ships it. excubia-app is the reference and sets the lint rules: an app that differs converges on it, never the other way round, and no app moves from Vite+ back to plain Vite, ESLint or Prettier. externa-core still runs ESLint + Prettier and moves to Vite+ in 1.5.0 (externa-core#609).

##### Local development

**Laravel Herd** serves the app at `https://<app>.test` (`herd link`, `herd secure`); `composer run dev` starts Vite, the queue worker and Pail. No Sail or Docker for day-to-day work: Docker is the distribution image, not the dev environment.

- **One database per app**, named after it (`externa_core`, `excubia_app`). Local data is disposable: for a clean state, empty and re-seed it (`php artisan migrate:fresh --seed`); never create per-version or per-branch copies. Before dropping a database, check which `.env` files use it. Production databases only when the maintainer says so.
- **Clean up after a task.** Worktrees, clones, Herd sites or workspaces and extra databases are fine while a task needs them; when its PR is merged or the work is dropped, remove them (`git worktree remove`, `herd unlink`, drop the database) after checking for unpushed work.

**Details:** `.qiick/standards/stack.md`. Read it before bootstrapping an app, or adding, removing or upgrading a dependency, the lint / format setup or the quality-gate scripts.

#### Code standard

Every file follows classic craftsmanship, including files nobody reads line by line: self-explanatory names, English documentation of _why_ and of domain invariants, clean structure with breathing room, and no duplication.

##### Simplest thing that works

Stop at the first rung that holds: YAGNI, standard library, platform, installed dependency, one line, then the minimum code.

- No unrequested abstractions, dependencies or boilerplate. A new Composer or npm dependency needs the maintainer's OK.
- Deletion over addition. Boring over clever. Fewest files possible.
- Mark an intentional shortcut with a `ponytail:` comment that names the ceiling and the upgrade path.
- Never lazy about trust-boundary validation, data-loss prevention, security, accessibility, or anything explicitly requested.
- Non-trivial logic leaves one runnable check behind (a test).

##### Current sources, not memory

Before writing framework or library code, or copying a component from an upstream registry (shadcn, AI Elements), read the current official page for the installed version. Training data and old copies go stale: until 2026-11-09 the registry's AI Elements were a superseded release, and nobody had compared them with the current pages. A file copied from upstream names its source page in its header comment, and the registry README records the source and date of every upstream item.

##### Names: full words

- No single letters (`x`, `e`, `q`) and no abbreviations (`cfg`, `res`, `req`, `val`, `arr`, `tmp`, `idx`, `usr`, `ctx`, `def`, `cb`). Full words even at 30+ characters (`$collectionFieldDefinition`, `handleFormSubmissionError`).
- `i`, `j`, `k` only as indices of tight numeric loops; prefer `foreach ($collectionItems as $collectionItem)`, `for...of`, `.map()`.
- **Booleans** (variables, columns, custom props, boolean-returning methods) start with `is`, `has`, `can` or `should` (`$isEmailVerified`, `canManageCollection()`, `shouldBroadcastImmediately`). Exempt: names fixed by a framework or library contract (see [Contracts](#contracts-never-break-them)).
- **Collections** use specific plurals (`$publishedArticles`) or explicit maps (`$usersIndexedById`), never `$data`, `$list`, `$arr`.
- Every class of our own ends with its role suffix (`*Controller`, `*Action`, `*Service`, `*Data`, `*Enum`, `*Job`, …); only Eloquent models use bare nouns. The full table is in the details.
- **Never rename framework or package scaffolding:** files native to Laravel or published by a package keep their upstream names: Fortify actions (`app/Actions/Fortify/*`), starter-kit files (`HandleInertiaRequests`, `HandleAppearance`, `app/Concerns/*`), default service providers, vendor-published files (Spatie, Horizon, Telescope, Pennant, Passport), and frontend primitives (`shadcn/ui` files in `components/ui/`). They are listed in the repo's `tests/Arch/naming-architecture.php`, never renamed.

##### Architecture: five layers

1. **Thin controllers.** At most the 5 resource methods or one `__invoke`, about 10 to 15 lines each, doing three things: receive a typed `*Request`, call an `*Action` / `*Service` with a `*Data` DTO, return a `*Resource`, JSON or an Inertia page. No business logic, no `DB` facade.
2. **Domain layer.** Writes in `app/Actions/*Action.php`, orchestration and reads in `app/Services/*Service.php`, typed `readonly` DTOs in `app/Data/*Data.php` instead of `array<string, mixed>` between controllers, actions, services and jobs.
3. **Clean models.** Relationships, `casts()`, accessors / mutators, scopes (or an `app/Builders/*Builder.php`), table constants. Never HTTP calls, disk or S3 work, or multi-step orchestration in a model.
4. **Authorization in Policies**, called through `Gate::authorize()` or a FormRequest `authorize()`, not scattered checks.
5. **One domain for every surface.** The Inertia UI, the REST / GraphQL API, MCP / AI tools and the CLI call the same `*Action` and `*Service` classes; domain logic is never duplicated per surface.

The Pest architecture test (`tests/Arch/NamingArchitectureTest.php`) enforces the suffixes, readonly DTOs, `declare(strict_types=1)`, no debugging helpers, no `env()` outside `config/`, no `DB` facade in controllers, and at most five public methods per controller (existing debt ratcheted in `naming-architecture.php`).

##### Formatting

- Curly braces on every `if`, `else`, `for`, `foreach`, `while`, `do`. No single-line braceless control statements.
- A blank line before and after control-flow blocks (`if`, `return`, `for`, `foreach`, `while`, `switch`, `try`, `throw`); ESLint `@stylistic/padding-line-between-statements` and Pint enforce it where configured.
- `declare(strict_types=1);` in every PHP file you write or touch.
- No `dd`, `dump`, `ray`, `var_dump`, `console.log` left behind. No `env()` outside `config/`.
- Strict DRY: extract shared validation rules, scopes, DTO shapes and UI primitives instead of copying blocks.

##### Documentation (English PHPDoc / JSDoc)

This replaces the "comment only exceptionally complex logic" default of Laravel Boost and similar guidelines.

- **Class, component and hook docblock** (mandatory): the unit's responsibility and why it exists.
- **Method and function docblock** for what the signature cannot say:
    1. Array shapes and generics for PHPStan / Larastan (`@param array{title: string, slug: string} $validatedPayload`, `@return Collection<int, CollectionItem>`).
    2. Side effects: queued jobs, dispatched events, filesystem / S3 writes, external HTTP calls.
    3. Business invariants, why a guard exists, `@throws` conditions.
- No noise that restates the code (`// set loading to true`).

##### Contracts: never break them

1. **Migrations are forward-only.** Never edit, rename or delete a migration in `database/migrations/` once it is merged into a released version: existing self-hosted installs would no longer upgrade. A schema or stored-data change is a new migration with a safe backfill. The reusable `migrations-frozen.yml` fails CI when a migration on `main`, on the latest tag or in `database/migrations/.frozen` changes. A repo that has not released yet may state a pre-release exception in its own section.
2. **Framework method contracts keep their names** (no `is` / `has` prefix forced on them): `FormRequest::authorize()`, Policy methods (`viewAny`, `view`, `create`, `update`, `delete`, `restore`, `forceDelete`), `Rule::passes()` / `ValidationRule::validate()`, `broadcastWhen()`, `shouldQueue()`.
3. **Native and library props keep their names:** `disabled`, `required`, `checked`, `open`, `defaultOpen`, `readOnly`, `asChild`, `modal`, `preserveScroll`, `preserveState`, `replace` (DOM, React, Radix / shadcn, Inertia). The prefix rule applies only to our own props and state.
4. **Wire formats stay stable** when a PHP class is renamed: REST JSON keys, GraphQL type and field names, webhook payloads, `broadcastAs()` names, Inertia page paths, named routes, morph maps (`Relation::enforceMorphMap`), queued job class names. Enum backing values stay identical.
5. **Convention-driven directories** (`app/GraphQL/`, `app/Ai/`, MCP tools) keep the names their resolver expects unless the resolver mapping changes in the same PR.
6. **Mechanical renames and behaviour changes never share a PR.** Land the `git mv` + import updates first with green tests, then extract or change logic in a separate PR.

**Details:** `.qiick/standards/code.md`. Read it before creating or renaming a file, class, enum, job, command or other PHP / TypeScript unit.

#### Backend conventions

How the five layers look in a qiick app. Each topic has a page with the externa-core (or excubia-app) files to copy; read it before touching that area.

| Topic                                                  | Page                                                                          |
| ------------------------------------------------------ | ----------------------------------------------------------------------------- |
| Auth pages, 2FA, passkeys, SSO through Signaculum      | https://github.com/qiick-io/app-ui/blob/develop/docs/auth.md                  |
| Roles, permissions, policies, `<app>:sync-permissions` | https://github.com/qiick-io/app-ui/blob/develop/docs/roles-and-permissions.md |
| Error pages 403 / 404 / 500 / 503                      | https://github.com/qiick-io/app-ui/blob/develop/docs/error-pages.md           |
| REST API, OpenAPI, API keys and scopes                 | https://github.com/qiick-io/app-ui/blob/develop/docs/api.md                   |
| Realtime: Reverb, channels, presence                   | https://github.com/qiick-io/app-ui/blob/develop/docs/realtime.md              |
| Security headers                                       | https://github.com/qiick-io/app-ui/blob/develop/docs/security-headers.md      |
| Logging and activity (audit) log                       | https://github.com/qiick-io/app-ui/blob/develop/docs/logging-and-audit.md     |
| AI backend: providers, tools, approvals                | https://github.com/qiick-io/app-ui/blob/develop/docs/ai-backend.md            |

##### Requests and controllers

- Every write takes a `FormRequest` in `app/Http/Requests/<Area>/`: `Store<Model>Request`, `Update<Model>Request`, `Bulk<Model>ActionRequest`; shared rules live in `app/Http/Requests/Concerns/*Trait.php` (`ValidatesListPerPageTrait`, `ValidatesSearchQueryTrait`). `authorize()` checks the permission (or the policy); `rules()` validates the whole payload at the trust boundary.
- A controller method receives the request, builds a `*Data` DTO from `validated()`, calls one `*Action` (write) or `*Service` (read), and returns an Inertia page, a redirect with a flash message, or a `*Resource`. Index pages paginate on the server (`?page`, `?per_page`, `?sort`, `?search`).
- Wayfinder generates the frontend route helpers (`@/routes/...`, `@/actions/App/Http/Controllers/...`): never hard-code a URL in TypeScript.

##### Permissions and policies

- Permissions are a string-backed `app/Enums/PermissionEnum.php` (`CanShowUsers = 'can-show-users'`, `can-<verb>-<resource>`), roles a `RoleEnum` (`super-admin`, `admin`, …), stored with `spatie/laravel-permission`.
- `php artisan <app>:sync-permissions` creates the missing permissions from the enum, grants new ones to `admin`, clears the permission cache and writes `resources/js/enums/permission-enum.ts`. `<app>:install` and `<app>:upgrade` run it; never seed permissions by hand.
- Route groups are gated with the `permission:<value>` middleware; record-level decisions (ownership, state) live in `app/Policies/*Policy.php`, called through `Gate::authorize()` or `authorize()`. `Gate::before` grants `super-admin` everything.
- The UI receives `auth.permissions` as a shared Inertia prop and hides what the user cannot do (`useCan()`); the server check is the one that counts.

**Details:** `.qiick/standards/backend.md`. Read it before adding or changing an API, security headers or rate limits, activity logging, broadcasting, AI tools, health endpoints or Artisan commands.

#### Testing

- Pest for PHP (`*Test.php`), Vitest or `node:test` for TypeScript / scripts. Test behaviour, not private structure.
- Every behaviour change adds or updates a test that fails without the change. Pure copy, styling or layout changes need none.
- Never delete, skip or weaken an existing test to get green; ask the maintainer first.
- No real network, AI provider or external process in tests: `Http::fake()`, `Process::fake()`, recorded fixtures, fake providers.
- Use model factories (and their states) for test data.
- UI flows that only a browser proves (navigation, dialogs, keyboard, focus) get a Pest browser test (`tests/Browser`, `pestphp/pest-plugin-browser`) where the repo has the plugin; check light and dark.
- `tests/Arch/NamingArchitectureTest.php` stays green. A new class satisfies its role suffix; scaffolding is listed in `tests/Arch/naming-architecture.php`, never renamed.
- Run the narrowest tests while iterating (`--filter`, a file path), and the repo's full quality gate (listed in its own section) before every push.

#### Git, commits and pull requests

- **English everywhere in the repo:** code, comments, docs, commit messages, issues, pull requests.
- **Conventional Commits:** `type(scope): summary` in the imperative, lower case, no trailing period. Types: `feat`, `fix`, `refactor`, `perf`, `docs`, `test`, `chore`, `ci`, `build`, `security`. `!` or a `BREAKING CHANGE:` footer for breaking changes. commitlint checks PR titles in CI through the reusable `qiick-io/app-ui/.github/workflows/pr-title.yml` (check `pr-title / commitlint`), which every repo calls on `pull_request` with the `edited` type.
- **One issue, one branch, one pull request.** Branch from `develop` as `<type>/<issue>-<kebab-slug>` (`feat/42-project-registration`). Never commit directly to `develop` or `main`.
- Pull requests target `develop` and are **squash-merged**, so the PR title becomes the commit: make it a valid Conventional Commit. The body says what changed, why, and `Closes #<issue>`.
- **Every issue and pull request gets its metadata when it is created:** assignee (the maintainer), labels from the repo's set, the milestone of the release it belongs to, and the repo's GitHub project when it has one.
- **Branch protection** (every public repo): `develop` and `main` change only through a pull request with green required checks; admins are not exempt. When a PR is behind its base, update the branch (`gh pr update-branch`) and wait for the checks again. `main` takes **merge commits** only (a ruleset): release PRs, and the merge of `main` back into `develop` after a tag, which is a pull request too.
- Never force-push a shared branch, never `--no-verify`, never `--admin` or any branch-protection bypass.
- No attribution or `Co-authored-by` trailers for tools or agents.
- Never commit `.env`, secrets, tokens, personal phone numbers or email addresses; only `.env.example`.
- Nothing personal in tracked files: no absolute local paths (`/Users/…`), no one's own editor or agent configuration (keep it in `.gitignore`), and "the maintainer" instead of people's names in code, comments and docs. Describe what code does rather than which product it imitates; naming a product is fine where it is an integration (an importer, a compatible endpoint).
- Ask the maintainer before: a new dependency, anything outside the issue's scope, repo settings (branch protection, required checks, secrets), paid services or accounts.

#### Releases: never without the maintainer

`develop` is the integration branch. `main` holds releases only.

Agents may, when asked for a release:

- open a `develop` → `main` release pull request with a full body (changelog, included PRs), then **stop**.

Agents must not, unless the maintainer explicitly says so in this conversation:

- merge a release pull request or anything into `main` / `release/*`;
- bump versions, create tags (`vX.Y.Z` or any other), run `gh release create` or publish a GitHub Release;
- push to `main` / `master` / `release/*`;
- publish packages (Packagist, npm) or images (Docker Hub, GHCR);
- change repository visibility.

How a release goes, for reference: release PR → the maintainer merges it with a **merge commit** (never squash: `main` must stay an ancestor of `develop`) → tag `vX.Y.Z` on the merge commit → GitHub Release titled exactly with the tag, listing the included PRs → `main` is merged back into `develop` through a pull request, also with a merge commit.

**Details:** `.qiick/standards/release.md`. Read it before preparing a release PR or the CHANGELOG, bumping a version, or asking for required checks.

#### Automated checks

Machines enforce these in CI; keep them green, and never silence one to get green. Fix the new finding; a baseline only shrinks.

- **PHP quality floor:** PHPStan (Larastan) at level **8** at least in apps and **`max`** in packages; the level never drops and `phpstan-baseline.neon` only shrinks. Apps run Pest with line coverage in CI and fail below the committed `coverage-floor.txt`, which only rises; packages keep a fixed minimum per path. Both run in the reusable `php-quality.yml`.

**Details:** `.qiick/standards/checks.md`. Read it before fixing a failing check, or touching a baseline, `knip.json`, a ratchet or the strict-model setup.

#### Self-hosted app distribution

For apps a customer installs (not for Composer packages, which ship with plain `composer require` / `update`). externa-core is the reference implementation. In short: install with `composer create-project qiick/<app>` and `php artisan <app>:install`, update with `php artisan <app>:upgrade` (never `create-project`).

**Details:** `.qiick/standards/distribution.md`. Read it before changing install, upgrade, the Docker image, the release workflow, the docs pages or Packagist metadata.

#### UI (qiick app UI)

No agent invents a layout, a component, a colour or a component position in a qiick app. The only exceptions are explicit overrides (rule 2).

##### Before any UI change

Patterns come in two tiers. **Core** patterns bind every qiick app. **Optional** patterns are accepted solutions that bind only the apps that adopt them, by listing them in `qiick-standards.json` `patterns`. Optional patterns adopted in this repo: none.

1. Find the pattern in the tables in the details and read its page: each one names the registry items, and the reference app files where they are not in the registry yet.
2. Install it from the `@qiick` registry with `npx shadcn add @qiick/<name>`; do not rewrite it. The registry list: https://github.com/qiick-io/app-ui/blob/develop/registry/README.md
3. If the registry does not have it yet, copy it from the reference app the page names, file by file.
4. When a screen needs what an optional pattern solves and this app has not adopted it, ask the maintainer to adopt it (add it to `patterns`). Do not build a variant of your own.
5. If no core or optional pattern fits, **stop and ask the maintainer**. A new solution is proposed in app-ui as an optional pattern, never designed inside one app.

##### Hard rules

1. **Source of truth.** The `@qiick` registry; until an item ships there, the reference app its pattern page names. Copy, never redesign.
2. **Overrides.** An intentional difference from the registry is listed in the app's `app-ui.overrides.json` with a reason per file. Any other difference fails the drift check in CI.
3. **Primitives.** shadcn components only (the registry `ui` items). Icons: `lucide-react` only. No Headless UI or other component libraries.
4. **Colours.** Theme tokens only (`bg-primary`, `text-muted-foreground`, `border-sidebar-border`, …). No hex and no raw palette classes (`bg-teal-600`, `text-neutral-500`) in `className`; no arbitrary font sizes (`text-[11px]`).
    - AI surfaces use the violet `ai` token set (`bg-ai`, `text-ai-text`, `border-ai-border`, …), never the brand primary.
    - Status colours (`success`, `warning`, `destructive`, `info`) carry status only; never use one as a brand or primary colour.
5. **Control scale.** `sm` 24px, default 32px, `lg` 40px. The default (32px) everywhere: headers, toolbars, forms, settings. `sm` only for compact inline actions (table row icons, chips); `lg` for rare large CTAs.
6. **Accessibility.** WCAG 2.2 AA in light, dark and high contrast: visible focus, keyboard reachability, labels / `aria-label` on icon-only controls, contrast. Check every UI change in light and dark.
7. **Positions are part of the pattern.** Where a control sits (primary action far right, filters left, pagination counter left / pages centre / rows per page right, …) is copied like the code.
8. **Presence.** shadcn `AvatarBadge` inside the `Avatar`, bottom-right. Users: green online, solid grey offline (never transparent). The realtime badge on the signed-in user: green connected, amber connecting, grey disabled, red only when the connection is lost. Member lists show avatars without presence, then `AvatarGroupCount` "+N".

##### Theme

Tokens come from the `@qiick/theme` registry item: one shared base (surfaces, the two-tone shell, text, borders) plus brand-only presets (Teal = Externa, Blue = Excubia). An app picks its preset and never edits surface tokens. This repo's preset: **Teal**.

**Details:** `.qiick/standards/ui.md`. Read it before making any UI change (it has the table of pattern pages).

</details>

### Repo rules to add below the block

- Product: the two sentences of section 1, and the one-repo decision (section 0 box). Source of truth: the design note (section 0), `docs/FORMAT.md`, `docs/CONTRACTS.md`, `docs/SPEC.md`.
- Anything marked `[plan]` in the note, or labelled `needs-decision`, is not approved: do not build it.
- Configurable never-ingest denylist via `INSTITUTA_INGEST_DENYLIST` (section 3.6). Never proxy writes to other products (section 1).
- Working name: no public use, no registry or package publishing, repo private.
- The brain module (`app/Brain`) uses nothing from the rest of the app (section 2.1, arch test). Its roles: `Reader`, `Adapter`, `Resolver`, `Scanner`, `Data` (readonly), `Enum`, `Contract`, `Exception`; MCP tools are `*Tool` (configure them in `tests/Arch/naming-architecture.php`).
- `docs/CONTRACTS.md` and `docs/FORMAT.md` are public contracts: change them only when the issue says so, and update tests, README and CHANGELOG together.
- Quality gate (section 8). Migrations: forward-only once the first release is tagged (`migrations-frozen` enforces released migrations); before that, never edit a migration that is already on `develop` without saying so in the PR body.
- A later extra piece gets a descriptive repo name (`instituta-cli`, `instituta-sdk-php`), never a second "core".

---

## 8. Quality gate (before every push)

Scripts as excubia-app has them:

```bash
composer install && npm ci
npm run build
vendor/bin/pint --test
vendor/bin/phpstan analyse                 # level 8, no baseline
node scripts/qiick-ratchet.mjs phpstan --min-level 8
php artisan test                           # incl. tests/Arch and the isolation suite
npm run lint                               # vp check + check-ui-tokens
npm run types:check                        # tsc --noEmit
npm run knip
npm run lint:i18n && npm run lint:standards
node ../../app-ui/scripts/check-drift.mjs  # registry and standards drift
```

CI: the app's own workflow (PHP 8.4 and 8.5 on SQLite, a PostgreSQL 17 job for search, MySQL 8 (app tests; search returns "not supported"), browser + accessibility smoke on PRs) plus `.github/workflows/qiick.yml` (`pr-title / commitlint`, `app-ui-drift / standards`, `app-ui-drift / drift`, `php-quality / php-quality`, `ui-quality / ui-quality`, `migrations-frozen / migrations-frozen`, `distribution-smoke / smoke`). Call the shared workflows with exactly those job ids.

Plus `php artisan instituta:read --path=tests/Unit/Brain/Fixtures/<repo> validate` on the fixture repos.

UI changes: check the page in the browser on `https://instituta-core.test`, light and dark, in Italian too.

---

## 9. Git, PRs, releases and settings

- English in the repo; Conventional Commits (`feat`, `fix`, `refactor`, `perf`, `docs`, `test`, `chore`, `ci`, `build`, `security`); one issue → one branch → one PR into `develop`; squash merge; PR body with what / why / `Closes #<issue>`.
- **Metadata on every issue and PR when you create it:** assignee `LucaGiardi20`, labels from the repo's set (`type:*`, `area:*`, `priority:*`, `effort:*`, `needs-decision` for anything Luca must decide), milestone (`v0.1.0` = the read-only MVP, `v0.2.0` = writes as proposals, `Later`), and the private GitHub project **Instituta (#4)**.
- **Never push to `main`**, never commit directly to `develop`, never force-push a shared branch (update a PR branch by merging `develop` into it), never `--no-verify`, never `--admin` or any bypass. Commit as `LucaGiardi20`; check `gh api user -q .login` before any `gh` write. No attribution or `Co-authored-by` trailers. Never commit `.env`, keys, tokens, personal paths (`/Users/…`), e-mail addresses or phone numbers.
- **Releases never without Luca's "rilascia"**: no merge into `main`, no tag, no GitHub Release, no Packagist, no GHCR. You may open a `develop → main` release PR when asked, then stop.
- **Never add `.github/dependabot.yml`** (qiick: Dependabot alerts only, no automatic PRs).
- Repository settings (branch protection, rulesets, required checks, secrets, visibility, projects) are Luca's. Private repos on the free plan cannot be protected (403); the protection rules apply by convention.
- Workflow changes go in their own commit, and the PR says which required check names Luca would add or rename.

---

## 10. Stop and ask Luca

Ask first, in Italian, and wait for his answer:

- **Any new dependency** (Composer or npm), including a graph library, a markdown parser, a git library; the stack packages of `docs/new-product.md` and `laravel/mcp` are already agreed.
- **Anything public**: making a repo public, Packagist, GHCR, npm, a domain, a docs site, a marketplace listing, a social post, a trademark or company filing.
- **Licensing** of anything other than MIT, and any third-party code or asset with another licence (site-ui contains Tailwind Plus-derived code: it may be **ingested as private content** for dogfood, never copied into these repos).
- **Naming**: the product name, the package name, the `instituta:` Artisan namespace if the attorney says no (Cogitata is the reserve).
- Any change to `docs/CONTRACTS.md` or `docs/FORMAT.md` after it is approved.
- Extracting the brain module, or creating any second repo (a Composer SDK, a CLI): only when Luca says so, with a descriptive name, never a second "core".
- Anything marked `[plan]` in the note, any feature from section 2.2 "Out", and every screen with no app-ui pattern (the reading view is already settled in "Decisions already taken").
- A graph library, and anything "Decisions already taken" does not cover. Never ask again what that table answers.
- Any access to organisations on the never-ingest denylist: never.

## 11. How to report to Luca (Italian)

After each PR: the PR link, two or three sentences on what it does, the checks you ran and their result, what you verified in the browser, what is **not** done or not verified, and the decision you need from him. Keep a hand-over file per run in the shared notes folder if Luca gives you one (`instituta-core-run-N.md`: state of each PR, open decisions, gotchas), so the next agent can re-check everything.
