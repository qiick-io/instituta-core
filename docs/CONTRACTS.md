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
  "principal_id": "ulid",
  "principal_type": "person|agent|service",
  "scope_type": "organisation|company|product",
  "scope_id": "ulid",
  "role": "owner|member|guest",
  "max_visibility": "public|internal|restricted",
  "path_prefix": "string|null",
  "area": "string|null"
}
```

### 2.3 Item summary (list / search)

```json
{
  "id": "ulid",
  "stable_key": "string",
  "title": "string",
  "summary": "string",
  "area": "string",
  "type": "string",
  "visibility": "public|internal|restricted",
  "origin": {
    "scope_type": "organisation|company|product",
    "scope_id": "ulid|null",
    "source": "string",
    "path": "string",
    "commit": "string|null",
    "content_hash": "sha256 hex"
  },
  "citation": { "source": "…", "path": "…", "section": null, "commit": "…" }
}
```

### 2.4 Freeze

When a scope is frozen, every contract call for that scope answers from the pinned vault version and includes:

```json
{ "frozen": true, "vault_version_id": "ulid", "label": "instituta@vN" }
```

## 3. Operations

| Operation | MCP tool | REST | Artisan (`instituta:read`) |
|---|---|---|---|
| Who am I / scopes | `instituta_scopes` | `GET /scopes` | (N/A locally; implied single owner) |
| List | `instituta_list` | `GET /items` | `list` |
| Search | `instituta_search` | `GET /search` | `search` |
| Outline | `instituta_outline` | `GET /items/{id}/outline` | `outline` |
| Get by id | `instituta_get` | `GET /items/{id}` | `get` |
| Get by section | `instituta_get_section` | `GET /items/{id}/sections/{anchor}` | `get` + section |
| Table rows | `instituta_table` | `GET /items/{id}/tables/{key}` | (via get / table) |
| Resolved view | `instituta_resolve` | `GET /products/{id}/resolved` | `resolve` |
| Graph neighbours | `instituta_graph` | `GET /items/{id}/neighbours` | `graph` |
| Similar sections | `instituta_similar` | `GET /similar` | — (needs embeddings) |
| Validate tree | — | — | `validate` |
| Propose write | `instituta_propose` | `POST /proposals` | — |
| List proposals | `instituta_list_proposals` | `GET /proposals` | — |
| Update proposal | `instituta_update_proposal` | `PATCH /proposals/{id}` | — |

OpenAPI 3.1 lives at `resources/openapi/instituta-v1.yaml` and is served at `GET /api/v1/openapi.json` with a route-coverage test.

**MCP prompts and resources** (HTTP server, #202). They package reads the tools already offer; they add no data an agent could not read itself.

| Kind | Name | Content |
|---|---|---|
| Prompt | `instituta_product_context` (`product_id`, `area?`) | The product's resolved view plus how to read deeper and how to propose changes |
| Prompt | `instituta_propose_change` (`item`, `goal?`) | The item's sections with markdown and `section_hash`, `content_hash`, and the proposal steps |
| Resource | `instituta://brain/map` | Areas of the perimeter with item counts (empty areas are gaps) |
| Resource template | `instituta://products/{product_id}/resolved` | The resolved view of one product, as `instituta_resolve` |

The local stdio server (`instituta:mcp`) offers the same reads as HTTP for one folder (`instituta_get_section` and `instituta_table` included); writes, `instituta_similar`, prompts and resources are HTTP only.

### 3.1 `instituta_scopes` / `GET /scopes`

**Returns:** principal, resolved perimeter, list of visible scopes the caller may switch to (within grants).

### 3.2 `instituta_list` / `GET /items`

**Query:** `scope` (required), `area`, `type`, `cursor`, `limit` (default 25, max 100).

**Returns:** cursor-paginated item summaries. **No bodies.**

### 3.3 `instituta_search` / `GET /search`

**Query:** `scope` (required), `q` (required), `area`, `limit` (default 20, max 50).

**Behaviour:** apply perimeter first, then rank. Drivers: `pgsql` (reference), `sqlite` (FTS5). MySQL: not supported in v0.1.0 → clear error (`501` on REST, tool error on MCP).

**Returns:** ids, titles, summaries, short snippets, score, citation. **No full bodies.**

### 3.4 `instituta_outline` / `GET /items/{id}/outline`

**Returns:** section anchors, headings, levels, block types. **No bodies.**

### 3.5 `instituta_get` / `GET /items/{id}`

**Returns:** full content as typed blocks + citation. Honour visibility and perimeter; forbidden scope → empty / not found, not an error leak of existence across tenants.

Each section also carries its source `markdown` (heading line included) and its `section_hash` (sha256 of that markdown). Writers edit from `markdown`, never from the plain-text `body`, and send `origin.content_hash` or `section_hash` back as the base of a proposal (§3.10).

### 3.6 `instituta_get_section` / `GET /items/{id}/sections/{anchor}`

**Returns:** that section only (blocks, `markdown`, `section_hash`, the item's `content_hash`, citation).

### 3.7 `instituta_table` / `GET /items/{id}/tables/{key}`

**Query:** optional `filter` (exact match on column values; details in implementation).

**Returns:** structured rows for `table_key`.

### 3.8 `instituta_resolve` / `GET /products/{id}/resolved`

**Query:** optional `area`.

**Resolution order (default):** **product > company/client > organisation**, every value with its **origin** (scope, source, file, commit). `inherits_from_parent = false` isolates a product (case 3). Shared sources are referenced by id + version (case 6), never copied.

**Returns:** resolved keys, origins, ACL cuts, token estimate.

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
  "source_id": "ulid|null",
  "base_content_hash": "sha256 hex|null",
  "section_anchor": "string|null",
  "base_section_hash": "sha256 hex|null"
}
```

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

## 4. Errors

| Situation | HTTP | MCP |
|---|---|---|
| Unauthenticated | 401 | tool error |
| Authenticated but outside perimeter / unknown id | 404 | empty / not found (no cross-tenant leak) |
| Validation | 422 | tool error with field messages |
| Search on MySQL | 501 | tool error `"search driver not supported"` |
| Ability missing / write refused | 403 | tool error |
| Rate limited | 429 | tool error |

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
