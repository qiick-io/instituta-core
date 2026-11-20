# Instituta public contracts

> **Working contract (approved 2026-11-13).** Public contract for Instituta (MCP + REST + Artisan). Until `v1.0.0-beta.1` this document may change; each change lands in a PR labelled `area:contract` with a CHANGELOG entry and updated tests. From `v1.0.0-beta.1` it is frozen (additive only, as excubia-core). A later Composer SDK (`instituta-sdk-php` or similar) MUST follow the same DTOs.

Everything is scoped by the caller's **perimeter**. Nothing accepts a scope wider than the authenticated principal / API key.

## 1. Surfaces

| Surface | Transport | Auth |
|---|---|---|
| MCP (HTTP) | Laravel MCP over HTTP | `Authorization: Bearer <api_key>` |
| MCP (stdio) | `php artisan instituta:mcp --path=<repo>` | Single-owner local perimeter (no multi-tenant DB) |
| REST | `/api/v1/…` | Same Bearer API keys |
| Artisan reader | `php artisan instituta:read --path=<repo> …` | Local folder; no key |

Shared abilities: `brain:read` (required for every HTTP contract call) and `brain:write` (proposals only). MCP is off by default (`mcp.enabled`). Writes stay off by default (`mcp.write_enabled` for MCP, `api.write_enabled` for REST). A key may hold `brain:write` only when a write kill switch is on, the key role is `owner` or `member` (never `guest`), and the principal has an owner/member grant. Writes never mutate the durable git store directly: they open or update **proposals** (git PR materialisation is a later step).

Rate limit (HTTP): `api` 60 requests / minute per key (as externa-core).

## 2. Common types

Snake_case JSON. Dates ISO-8601. Errors as Laravel JSON error responses.

### 2.1 Citation

```json
{
  "source": "string",
  "path": "string",
  "section": "string|null",
  "commit": "string|null"
}
```

### 2.2 Perimeter (resolved once per request)

```json
{
  "principal_id": "uuid",
  "principal_type": "person|agent|service",
  "scope_type": "organisation|company|product",
  "scope_id": "uuid",
  "role": "owner|member|guest",
  "max_visibility": "public|internal|restricted",
  "path_prefixes": ["string"],
  "areas": ["string"]
}
```

`areas` and `path_prefixes` limit the perimeter inside its scope (#334): an item is readable only when its area is one of `areas` and its path is one of `path_prefixes` or inside one (folders, without slashes at either end). An empty list means no limit on that axis. Every surface applies them: lists, reads, search (hits and counts), `instituta_context`, the graph and neighbours, the resolved view, changes, proposals, gap reports and notifications. Several grants on one scope (a person's own and their groups') add up: the union of their areas and of their folders (a grant without a limit lifts it), with the widest role and visibility. An API key's own `areas` and `path_prefixes` only narrow what its grants allow; a key asking for none of them is refused.

### 2.3 Item summary (list / search)

```json
{
  "id": "uuid",
  "stable_key": "string",
  "title": "string",
  "summary": "string",
  "area": "string",
  "type": "string",
  "visibility": "public|internal|restricted",
  "token_estimate": 640,
  "status": "draft|active|deprecated|superseded",
  "superseded_by": "stable_key|null",
  "decision_date": "YYYY-MM-DD|null",
  "effective_date": "YYYY-MM-DD|null",
  "review_date": "YYYY-MM-DD|null",
  "is_review_overdue": false,
  "origin": {
    "scope_type": "organisation|company|product",
    "scope_id": "uuid|null",
    "source": "string",
    "path": "string",
    "commit": "string|null",
    "content_hash": "sha256 hex"
  },
  "citation": { "source": "…", "path": "…", "section": null, "commit": "…" }
}
```

### 2.3.1 Token estimates

Every read says roughly what reading it costs, so an agent plans within its context budget (#230): `token_estimate` on each item summary (the whole item), each outline section, each search hit (the hit's section, or the item when the hit has none) and each section read; the resolved view's `token_estimate` is the sum of its entries' estimates.

The estimate is **characters ÷ 4, rounded up**, of the section's source markdown (its plain text when there is no markdown), and an item's estimate is the sum of its sections (its body when it has none), so outline, section and item agree. It is computed at sync and stored, never per request. It is a planning figure, not a tokenizer count: code, tables and languages other than English can differ by a third either way, and frontmatter is not counted.

### 2.4 Freeze

When a scope is frozen, every contract call for that scope answers from the pinned vault version and includes:

```json
{ "frozen": true, "vault_version_id": "uuid", "label": "instituta@vN" }
```

## 3. Operations

| Operation | MCP tool | REST | Artisan (`instituta:read`) |
|---|---|---|---|
| Who am I / scopes | `instituta_scopes` | `GET /scopes` | (N/A locally; implied single owner) |
| List | `instituta_list` | `GET /items` | `list` |
| Context pack | `instituta_context` | `GET /context` | — |
| Search | `instituta_search` | `GET /search` | `search` |
| Changes since | `instituta_changes` | `GET /changes` | — |
| Outline | `instituta_outline` | `GET /items/{id}/outline` | `outline` |
| Get by id | `instituta_get` | `GET /items/{id}` | `get` |
| Get by section | `instituta_get_section` | `GET /items/{id}/sections/{anchor}` | `get` + section |
| Several sections | `instituta_get_sections` | `POST /sections` | — |
| Table rows | `instituta_table` | `GET /items/{id}/tables/{key}` | (via get / table) |
| Resolved view | `instituta_resolve` | `GET /products/{id}/resolved` | `resolve` |
| Graph neighbours | `instituta_graph` | `GET /items/{id}/neighbours` | `graph` |
| Similar sections | `instituta_similar` | `GET /similar` | — (needs embeddings) |
| Validate tree | — | — | `validate` |
| Propose write | `instituta_propose` | `POST /proposals` | — |
| List proposals | `instituta_list_proposals` | `GET /proposals` | — |
| Update proposal | `instituta_update_proposal` | `PATCH /proposals/{id}` | — |
| Report a gap | `instituta_report_gap` | `POST /gaps` | — |
| Brain health (owners) | `instituta_health` | `GET /health/brain` | `validate` (one folder) |

OpenAPI 3.1 lives at `resources/openapi/instituta-v1.yaml` and is served at `GET /api/v1/openapi.json` with a route-coverage test.

**MCP prompts and resources** (HTTP server, #202). They package reads the tools already offer; they add no data an agent could not read itself.

| Kind | Name | Content |
|---|---|---|
| Prompt | `instituta_product_context` (`product_id`, `area?`) | The product's resolved view plus how to read deeper and how to propose changes |
| Prompt | `instituta_propose_change` (`item`, `goal?`) | The item's sections with markdown and `section_hash`, `content_hash`, and the proposal steps |
| Resource | `instituta://brain/map` | Areas of the perimeter with item counts (empty areas are gaps) |
| Resource template | `instituta://products/{product_id}/resolved` | The resolved view of one product, as `instituta_resolve` |

The local stdio server (`instituta:mcp`) offers the same reads as HTTP for one folder (`instituta_get_section`, `instituta_get_sections` and `instituta_table` included); writes, `instituta_similar`, prompts and resources are HTTP only.

### 3.1 `instituta_scopes` / `GET /scopes`

**Returns:** principal, resolved perimeter, list of visible scopes the caller may switch to (within grants).

### 3.2 `instituta_list` / `GET /items`

**Query:** `scope` (required), `area`, `type`, `cursor`, `limit` (default 25, max 100).

**Returns:** cursor-paginated item summaries. **No bodies.**

### 3.2.1 `instituta_context` / `GET /context`

The first call for a task (#224): the context to work from, in one call instead of a round of search, outline and section reads.

**Query:** `scope` (required), `q` (required: the task in words), `product_id`, `area`, `budget` (tokens, default 4000, 500 to 16000).

**Returns**, best first and within the budget (estimates as §2.3.1):

- `resolved`: with `product_id`, the product's resolved active entries as one-liners (`resolution_key`, `title`, `summary`, `status`, citation);
- `sections`: the most relevant sections (hybrid search as §3.3, active entries first), already read: `markdown`, `anchor`, `heading`, `status`, `superseded_by`, `token_estimate`, citation;
- `omitted`: what did not fit (`kind` `section` with `item` and `anchor`, or `resolved_entry` with its key and path, each with its `token_estimate`), to fetch with `get_sections` when needed;
- `token_estimate` (what the pack holds), `budget` and `mode`.

Perimeter, freeze and audit rules as for every read (one audit row with the items included and the tokens returned). HTTP MCP, REST and the assistant (its first tool); not on the local stdio server, which has no hybrid search.

### 3.3 `instituta_search` / `GET /search`

**Query:** `scope` (required), `q` (required), `area`, `limit` (default 20, max 50), `mode` (`hybrid` default, `keyword`, `semantic`).

**Behaviour:** apply perimeter first, then rank. Keyword matching takes **any** term of the query, as a prefix, and ranks sections that match more terms higher (language-neutral: Postgres uses the `simple` configuration). With embeddings on, `hybrid` merges keyword and semantic hits by reciprocal rank fusion and `semantic` uses meaning alone; with embeddings off every mode runs as `keyword`. The answer's `mode` says what ran, and each hit's `match` (`keyword`, `semantic`, `both`) why it was found. Active entries are listed before draft, deprecated and superseded ones (relevance order within each group); every hit carries `status` and `superseded_by`. Drivers: `pgsql` (reference), `sqlite` (FTS5). MySQL: not supported in v0.1.0 → clear error (`501` on REST, tool error on MCP).

**Returns:** one hit per matching **section** (at most two per item, best first): item id, title, summary, the section's `anchor` and `heading`, a short snippet from that section, score, and a citation whose `section` is the anchor, so the caller opens it with `get_section` directly. **No full bodies.**

### 3.3.1 `instituta_changes` / `GET /changes`

For agents that keep a memory of the brain (instructions, caches): what changed since they last looked, instead of re-reading everything (#228).

**Query:** `scope` (required), `since` (required: an ISO 8601 date-time, or a vault version label `instituta@vN` for the changes after that version), `area`, `cursor`, `limit` (default 50, max 200).

**Returns:** `changes`, oldest first: `kind` (`created`, `updated`, `removed`), `item`, `stable_key`, `title`, `area`, `status`, `superseded_by`, `changed_sections` (for `updated`: the anchors whose content changed, appeared or disappeared), `changed_at`, citation (path and commit at that change); and `next_cursor`. An item changed by several syncs appears once per sync. Removed items stay listed after they are gone. Under a freeze, only changes up to the frozen version show. An unknown version label answers `not_found`, a `since` that is neither a date-time nor a label `validation_failed`.

Backed by the sync, which records per item what it created, changed or removed (unchanged files record nothing). Perimeter and audit as every read. HTTP MCP and REST only.

### 3.4 `instituta_outline` / `GET /items/{id}/outline`

**Returns:** section anchors, headings, levels, block types. **No bodies.**

### 3.5 `instituta_get` / `GET /items/{id}`

**Returns:** full content as typed blocks + citation, plus the item's `frontmatter`. Honour visibility and perimeter; forbidden scope → empty / not found, not an error leak of existence across tenants.

Each section also carries its source `markdown` (heading line included) and its `section_hash` (sha256 of that markdown). Writers edit from `markdown`, never from the plain-text `body`, and send `origin.content_hash` or `section_hash` back as the base of a proposal (§3.10).

### 3.6 `instituta_get_section` / `GET /items/{id}/sections/{anchor}`

**Returns:** that section only (blocks, `markdown`, `section_hash`, the item's `content_hash`, citation).

#### 3.6.1 `instituta_get_sections` / `POST /sections`

**Body:** `sections`: a list of 1 to 20 `{item, anchor}` (item: id, stable_key or path), for a caller that already knows what it needs (from search hits or an outline).

**Returns:** `sections`, one entry per request in the same order: `{item, anchor, section}` with `section` as §3.6 returns it, or `{item, anchor, error: "not_found"}` when the item or anchor is missing or outside the perimeter. One missing entry never fails the call. Same perimeter, freeze and audit rules as §3.6 (one audit row per call).

### 3.7 `instituta_table` / `GET /items/{id}/tables/{key}`

**Query:** optional `filter` (exact match on column values; details in implementation).

**Returns:** structured rows for `table_key`.

### 3.8 `instituta_resolve` / `GET /products/{id}/resolved`

**Query:** optional `area`.

**Resolution order (default):** **product > company/client > organisation**, every value with its **origin** (scope, source, file, commit). `inherits_from_parent = false` isolates a product (case 3). Shared sources are referenced by id + version (case 6), never copied.

**Returns:** resolved keys, origins, ACL cuts; each entry carries its item's `token_estimate` and the view's `token_estimate` is their sum (§2.3.1).


**Validity (#218).** For each resolution key the most specific **active** entry wins; a draft, deprecated or superseded entry never replaces an active one, and shows only when no active entry exists for its key (or with `include_inactive=true`). Every entry carries its `status` and dates.
### 3.9 `instituta_graph` / `GET /items/{id}/neighbours`

**Query:** `depth` (default 1, max 2 in the MVP).

**Returns:** nodes `{id, title, area}` and edges `{from, to, relation}` inside the perimeter. No hairball: start from the selected node.

### 3.10 `instituta_propose` / `POST /proposals`

Requires `brain:write` and the surface write kill switch. Opens a proposal; never writes the durable store.

**Body:**

```json
{
  "action": "create|update|delete",
  "stable_key": "string",
  "title": "string",
  "path": "string",
  "area": "string|null",
  "body": "string|null",
  "source_id": "uuid|null",
  "base_content_hash": "sha256 hex|null",
  "section_anchor": "string|null",
  "base_section_hash": "sha256 hex|null",
  "frontmatter": "object|null"
}
```

**Metadata (#219).** On `update`, `frontmatter` sets frontmatter keys (`null` removes one): `status`, `superseded_by`, `decision_date` / `effective_date` / `review_date` (`YYYY-MM-DD`), `title`, `summary`, `visibility`, `tags`, and other snake_case keys with scalar or list-of-string values. It may come alone (the body is kept byte for byte), with a whole-file body, or with a section edit; the base-version rules below apply as usual. Invalid keys or values are refused (422 / tool error).

**Base version.** A proposal names the version its author read, so it never overwrites newer content:

- `create`: the `stable_key` must not exist yet in the perimeter.
- Whole-file `update` and `delete`: `base_content_hash` (the item's `origin.content_hash`) is required, and `path` must be the item's current path. A body without frontmatter fences keeps the file's current frontmatter.
- One-section `update`: `section_anchor` plus `base_section_hash` (or `base_content_hash`); `body` is the section's new markdown, heading line included. Only that section is replaced; frontmatter and the other sections stay as they are, even if they changed meanwhile.
- A missing or stale base is refused when the proposal is opened (422 / tool error: re-read and propose again). Before writing, materialisation checks the file (or the section) again; if it changed, the proposal ends with materialisation status `conflict` and nothing is written.

**Returns:** proposal summary (id, status, action, stable_key, title, path, area, section_anchor, base_content_hash, scope, timestamps) plus `overlapping_open_proposals`: the ids of other open proposals that change the same item (whole file) or the same section. **Never returns `body`.**

### 3.11 `instituta_list_proposals` / `GET /proposals`

Requires `brain:write` and the surface write kill switch.

**Query:** optional `status` (`open|accepted|rejected`).

**Returns:** `{ "proposals": [ …summaries ] }` inside the perimeter. **No bodies.**

### 3.12 `instituta_update_proposal` / `PATCH /proposals/{id}`

Requires `brain:write` and the surface write kill switch. Accepts or rejects an **open** proposal in the perimeter.

**Body:** `{ "status": "accepted|rejected" }`

**Returns:** updated proposal summary. Git materialisation of `accepted` is out of this contract surface (implementation step).

**Who may accept.** Accepting is a human decision:

- A key can never accept a proposal that it, or its principal, opened.
- Keys of `agent` and `service` principals may reject but not accept, unless the install sets `INSTITUTA_AGENTS_CAN_ACCEPT_PROPOSALS=true`.
- A person deciding in the web UI may accept any open proposal in their perimeter (guests excepted).

A refused accept returns `422` (REST) or a tool error (MCP) with the reason, and the proposal stays `open`.

### 3.13 `instituta_report_gap` / `POST /gaps`

For an agent that finds something missing or wrong but cannot write the fix (#229).

**Body:** `kind` (`missing`: nothing answers the question; `contradiction`: two entries disagree; `unclear`; `outdated`), `summary` (one line, required), `question` (what led there), `related` (at most 10 `{item, anchor?}`, item by id, stable_key or path).

**Behaviour:** open to any key with `brain:read`, guests included: a report changes nothing in the brain. Related items and anchors must be inside the reporter's perimeter (otherwise `not_found`, and nothing is stored), so a report never names what the reporter cannot read. The report is stored for the scope, the organisation's users are notified, and the scope's **owners** see it on the Gaps page, where they turn it into a proposal (the propose drawer, prefilled; storing the proposal closes the report as `converted`) or dismiss it. Audited like every call.

**Returns:** `201` with the report's `id`, `kind`, `status` (`open`), `summary` and `related`.

### 3.14 `instituta_health` / `GET /health/brain`

What the last syncs left wrong in the perimeter (#231), for **owner** keys (others: `forbidden`). Read from the index the syncs wrote, so it always matches the latest sync.

**Returns:** `issues`, each with `code`, `severity` (`error` or `warning`), `item` and `stable_key` (null for file-level problems), `path`, `message` and `suggested_fix`; `counts` per code; `error_count` and `warning_count`. Codes:

| Code | Severity | Found when |
|---|---|---|
| `reader_issue` | error / warning | The reader could not parse a file (frontmatter, structure), from the source's last sync report |
| `skipped_file` | warning | The sync skipped files: one issue per source and reason, with the count and the first paths |
| `broken_link` | warning | A link to a brain file (markdown or wiki link, not a URL or an image) resolves to no item |
| `missing_superseded_by` | error | `superseded_by` names no entry in the organisation |
| `duplicate_resolution_key` | error | Several active entries claim the same `resolution_key` in the same scope (one issue per entry) |
| `review_overdue` | warning | An active entry's `review_date` is past |

The web dashboard shows the same list and counts to owners.

## 4. Errors

Every error carries a stable machine **code**: REST answers `{"message": "…", "error": "…"}` (plus `errors` on validation), an MCP tool error starts with `[error]` (`[stale_base] The content changed …`), and the MCP transport's JSON-RPC auth errors carry it in `error.data.error`. Branch on the error, never on the message: messages may be reworded or translated, codes never change once released.

| Code | HTTP | When | What the agent should do |
|---|---|---|---|
| `unauthenticated` | 401 | Missing, invalid, revoked or expired key; inactive principal | Stop and ask for a valid key. |
| `forbidden` | 403 | Refused for another reason | Stop; report the message. |
| `ability_missing` | 403 | The key lacks `brain:read` or `brain:write` | Ask for a key with the ability; do not retry. |
| `write_disabled` | 403 | Brain writes are off on this install / surface | Stop writing; report it. |
| `guest_refused` | 403 / 422 | Guest keys cannot write or decide proposals | Read only. |
| `not_found` | 404 | Unknown id, or outside the perimeter (no cross-tenant leak) | Re-search or re-list; never guess ids. |
| `out_of_perimeter` | 422 | The target is outside the key's perimeter | Use a key for that scope. |
| `validation_failed` | 422 | A parameter is missing or invalid (`errors` lists the fields) | Fix the parameters and retry. |
| `invalid_change` | 422 | The proposal mixes fields that do not go together (section on create, empty update, …) | Fix the request as the message says. |
| `invalid_frontmatter` | 422 | A frontmatter key is not snake_case or a value does not fit | Fix the frontmatter change. |
| `base_required` | 422 | An update sent no `base_content_hash` / `base_section_hash` | Read the item, send its hash. |
| `stale_base` | 422 | The content changed since it was read | Re-read the item or section, redo the change, propose again. |
| `path_mismatch` | 422 | `path` is not the item's current path | Use the path from the latest read. |
| `item_exists` | 422 | A create for a `stable_key` that exists | Propose an update instead. |
| `section_missing` | 422 | No section with that anchor | Re-read the outline. |
| `section_not_synced` | 422 | The section has no stored markdown yet | Edit the whole item, or wait for a sync. |
| `proposal_not_open` | 422 | The proposal was already decided | Re-list proposals. |
| `self_accept_refused` | 422 | The key or principal that opened a proposal tried to accept it | Leave it to another person. |
| `agent_accept_refused` | 422 | Agent and service keys cannot accept on this install | Leave it to a person. |
| `quota_exceeded` | 429 | A plan quota is used up | Stop; report it. |
| `rate_limited` | 429 | Too many requests | Wait and retry. |
| `embeddings_disabled` | 501 | Semantic search on an install without embeddings | Use `instituta_search` / `GET /search` (keyword). |
| `search_unsupported` | 501 | Search on a driver without full-text support | Use list / outline instead. |
| `request_failed` | other | Any other failure | Retry once, then report. |

Validation errors keep Laravel's `errors` object; an unknown id stays a plain 404 (no cross-tenant leak), and a refused accept leaves the proposal `open`.

## 5. Identity and audit

- Same API keys for MCP and REST.
- Key is bound to principal + perimeter + role + max visibility (only narrower than grants) + abilities.
- Each HTTP contract call may append an audit row to `contract_calls` (principal, key, session, tool/endpoint, scope, item ids returned, token estimate, timestamp). **Never** content or secrets.
- Frozen scopes serve the pinned vault version (§2.4).

## 6. Local reader CLI

```bash
php artisan instituta:read --path=<repo> list|search|get|outline|resolve|graph|validate
php artisan instituta:mcp --path=<repo>
```

These operate on the brain module over a local folder **without** the database index, with a single-owner perimeter. Same DTOs as HTTP.
