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

`POST /api/webhooks/sources/{source}` queues a sync of that source. No API key: the source secret authenticates the request. Rate limited to 60 requests a minute.

- **GitHub/Gitea/Forgejo HMAC:** send `X-Hub-Signature-256: sha256=<hmac>` over the raw body. A delivery id is required (`X-GitHub-Delivery`, `X-Gitea-Delivery` or `X-Forgejo-Delivery`); the delivery id and a `sha256(body)` claim are both recorded atomically after the signature matches, scoped to the source, for `INSTITUTA_WEBHOOK_REPLAY_TTL_SECONDS` (24h default). Replayed delivery ids or identical signed bodies with a new delivery id answer 200 `{"status":"duplicate"}` and trigger no sync. Identical-body duplicate protection lasts for the replay TTL (24h default): a sender that posts the exact same body again within that window gets one sync at most per TTL, even with a fresh delivery id, until the TTL expires or the body changes. A missing delivery id is refused with 400. A bad signature never burns the delivery id or body claim.
- **Standard Webhooks:** `webhook-id`, `webhook-timestamp` and `webhook-signature` are verified with a derived secret (`whsec_` + base64 of `hash_hmac('sha256', <source secret>, 'instituta-standard-webhooks-v1')`), including timestamp tolerance and replay protection. When both header sets are present, Standard Webhooks are preferred.
- **Residual replay note:** on the GitHub path, a holder of the source secret can still send a different signed body with a new delivery id. That is acceptable because sync ignores the webhook body, `SyncSourceJob` is unique per source for 600 seconds, the endpoint is throttled at 60 requests a minute per IP, and an unchanged source costs one `git fetch`.
- **Responses:** `ping` answers 200 without syncing; unknown source or one without a secret answers 404; bad signature/timestamp 401; replay store failure or dispatch failure answers 503 fail closed. Signatures and payload secrets are not logged.

## Related

- [Installation](installation.md)
- [Configuration](configuration.md)
- [FORMAT.md](FORMAT.md)
