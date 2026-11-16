# MCP and REST usage

Contract reference: [`CONTRACTS.md`](CONTRACTS.md) (approved working contract through `v1.0.0-beta.1` rules).

## Authentication

API keys are bearer tokens (`Authorization: Bearer ik_…`). Keys are scoped to an organisation (or tighter company/product scope) with a role and max visibility. Create/list/revoke: `instituta:key:*` or the owner UI.

## REST (`/api/v1`)

OpenAPI: `GET /api/v1/openapi.json`.

Typical reads: scopes, items, search, neighbours (graph), resolved product view, similar sections (when embeddings are enabled).

Writes use **proposals** only when `INSTITUTA_API_WRITE_ENABLED=true` and the key has `brain:write`:

- `GET/POST /api/v1/proposals`
- `PATCH /api/v1/proposals/{id}` (`accepted` / `rejected`)

Proposal bodies are not echoed in list responses or activity logs.

## MCP (HTTP `/mcp`)

Enable with `instituta.mcp.enabled`. Same perimeter as REST. The server's instructions tell agents how to read progressively.

- **Tools:** `instituta_scopes`, `instituta_list`, `instituta_search`, `instituta_similar` (by meaning, when `AI_EMBEDDINGS_ENABLED=true`), `instituta_outline`, `instituta_get`, `instituta_get_section`, `instituta_table`, `instituta_resolve`, `instituta_graph`; with `brain:write` and `INSTITUTA_MCP_WRITE_ENABLED=true`, `instituta_propose`, `instituta_list_proposals`, `instituta_update_proposal`.
- **Prompts:** `instituta_product_context` (load the rules that apply to a product) and `instituta_propose_change` (prepare a conflict-safe change to an item).
- **Resources:** `instituta://brain/map` and `instituta://products/{product_id}/resolved`.

Local stdio: `php artisan instituta:mcp --path=<folder>` serves the same reads over one folder, without writes, embeddings, prompts or resources.

## Artisan

| Command | Role |
| ------- | ---- |
| `instituta:read` | Read a local brain tree without the DB |
| `instituta:source:add` / `instituta:sync` | Register and sync sources (denylist enforced) |
| `instituta:sync-due` | Queue syncs for sources whose interval has passed (the scheduler runs it every minute) |
| `instituta:source:webhook` | Create, rotate or disable a source's push webhook secret |
| `instituta:embed` | Compute section embeddings |
| `instituta:export` / `instituta:erase` | Client subtree export and offboarding |
| `instituta:freeze` / `instituta:unfreeze` | Context freeze |

## Webhooks

`POST /api/webhooks/sources/{source}` queues a sync of that source. No API key: the body must be signed with the source's secret, GitHub style (`X-Hub-Signature-256: sha256=<hmac>`). A `ping` event answers 200 without syncing; an unknown source, or one without a secret, answers 404; a bad signature 401. Rate limited to 60 requests a minute.

## Related

- [Installation](installation.md)
- [Configuration](configuration.md)
- [FORMAT.md](FORMAT.md)
