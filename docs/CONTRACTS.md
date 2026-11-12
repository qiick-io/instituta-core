# Instituta public contracts

> **Draft, pending maintainer review.** This document is the proposed public contract for Instituta v0.1.0 (MCP + REST + Artisan). Implementations after plan step 3 build on it; incompatible changes need an explicit issue and maintainer OK. A later Composer SDK (`instituta-sdk-php` or similar) MUST follow the same DTOs.

Everything is scoped by the caller's **perimeter**. Nothing accepts a scope wider than the authenticated principal / API key.

## 1. Surfaces

| Surface | Transport | Auth |
|---|---|---|
| MCP (HTTP) | Laravel MCP over HTTP | `Authorization: Bearer <api_key>` |
| MCP (stdio) | `php artisan instituta:mcp --path=<repo>` | Single-owner local perimeter (no multi-tenant DB) |
| REST | `/api/v1/…` | Same Bearer API keys |
| Artisan reader | `php artisan instituta:read --path=<repo> …` | Local folder; no key |

Shared ability in the MVP: `brain:read`. The ability `brain:write` exists in the enum but is **refused** while writes are out of scope. MCP is off by default (`mcp.enabled`); writes stay off (`mcp.write_enabled`).

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
    "commit": "string|null"
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
| Validate tree | — | — | `validate` |

OpenAPI 3.1 (hand-written) will live at `resources/openapi/instituta-v1.yaml` and be served at `GET /api/v1/openapi.json` with a route-coverage test (implementation PR).

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

### 3.6 `instituta_get_section` / `GET /items/{id}/sections/{anchor}`

**Returns:** that section only (blocks + citation).

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
