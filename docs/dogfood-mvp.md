# Instituta MVP dogfood report

English status for Luca after the overnight isolation suite (step 15). Graph UI stays skipped.

## What was ingested

Local fixture sync (`tests/Unit/Brain/Fixtures/native` via `SourceSyncService`) indexes the native Instituta brain:

- Rules such as `rules/coding.md` (resolution key, sections, citations).
- Linked edges and structured tables from the fixture set.
- A `SourceVersion` labelled `HEAD` and one `VaultVersion` per organisation.

Other adapters exercised in unit fixtures (not yet wired as live org sources in CI):

- Basic Memory notes and relation verbs.
- Shadcn registry → structured table + component items.
- Plain markdown via the markdown adapter.

## What the scanner skipped

Fail-closed ingest behaviour verified in `BrainReaderTest` / `SourceSyncTest`:

| Skip / refusal | Example |
| --- | --- |
| `binary_without_sidecar` | `logo.png` without a text sidecar |
| `secret_pii:email_address` | Fixture email; match text is not stored in skip reasons |
| `secret_pii:private_key` | PEM material in the secrets fixture |
| Denylist path hit | Paths containing `klover` / `cybergrant` / `zerotredici` abort add/sync |

Skipped rows appear in `last_sync_report.skipped` without echoing the secret payload.

## What agents can ask

With a scoped API key (`brain:read`) over MCP HTTP or REST `/api/v1`:

- List / get / outline / section / table / resolve / scopes / search / graph neighbours inside the key perimeter.
- Freeze pins served commits; cross-tenant get returns null (MCP) or 404 (REST); graph of a foreign id returns empty nodes.
- Search ranks only perimeter hits (SQLite in app CI, PostgreSQL 17 job).

Human UI (session perimeter): map, tree, reading, tables, resolved (+ “what the agent would see”), sources, keys. Scope switcher rejects grants outside the principal. No graph screen in MVP.

## Isolation and a11y

- Cross-tenant suite: `tests/Feature/CrossTenantIsolationTest.php` (UI, MCP, REST, search, resolved, export, graph API).
- Supporting coverage remains in `TenancyIsolationTest`, `HttpMcpTest`, `RestApiTest`, `SearchTest`, `ExportFreezeTest`.
- Axe browser smoke: not installed in this repo (`pest-plugin-browser` absent; `ui-quality / accessibility` skips). WCAG gates that do run: `check-ui-tokens`, theme contrast via `ui-quality`, i18n ratchet (`en` / `it` / `de`).

## Gaps left for later

- Graph UI (explicitly skipped in MVP).
- Real-browser Pest + axe light/dark/high-contrast once the browser plugin is adopted.
- Live GitHub source dogfood beyond Process::fake mirrors.
