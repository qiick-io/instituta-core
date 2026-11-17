# Instituta brain repository format

> **Working contract (approved 2026-11-13).** Until `v1.0.0-beta.1` this document may change; each change lands in a PR labelled `area:contract` with a CHANGELOG entry and updated tests. From `v1.0.0-beta.1` it is frozen (additive only, as excubia-core).

Source of truth for product intent: the Instituta design note in the private knowledge repo and `docs/agents/cursor-kickoff.md`. Where this file and the kickoff disagree, this file wins.

## 1. Purpose

A **brain repository** is a git tree of human-readable knowledge (brand, rules, decisions, design systems, plans) that Instituta can index and serve to agents and humans under progressive disclosure. Git is the durable store; the app graph and search index are derived from the filesystem at sync time.

Instituta never proxies writes to other products. Content is user-owned.

## 2. Repository layout

At the root of a compatible tree (or under a configured `path` inside a larger repo):

```text
instituta.json          # optional manifest (see §3)
README.md               # optional
<areas>/…               # markdown (and sidecars) grouped by meaning
```

There is no mandatory directory taxonomy beyond what adapters understand. Native Instituta trees SHOULD group by **area** (rules, decisions, style/brand, glossary, design system, plans, connected systems, notes). Adapters map foreign layouts (Basic Memory, shadcn registry, plain markdown docs) onto the same item model.

### 2.1 What is never ingested

Matched case-insensitively on repository owner/name, remote URL, and path (enforced in app config and in the brain reader):

- Klover
- Cybergrant
- Zerotredici

Also skipped by hygiene rules (minimal MVP scanner + path rules): `vendor/`, `node_modules/`, generated build output, chat dumps, binaries other than the assets of §7 (and assets over 20 MB, `asset_too_large`), and files that look like secrets / PII (private keys, API tokens, `.env` bodies, passwords in URLs, emails and phone numbers in bodies). Matches are never stored; the sync report records only that a file was skipped and why.

## 3. Manifest (`instituta.json`)

Optional. When present:

```json
{
  "format": "instituta",
  "format_version": 1,
  "name": "optional display name",
  "default_visibility": "internal",
  "areas": ["rules", "decisions", "style", "glossary", "design-system", "plans", "connected-systems", "notes"]
}
```

Unknown keys are ignored by readers on a best-effort basis. `format_version` is an integer; readers MUST reject unsupported major versions explicitly.

## 4. Items

An **item** is one addressable unit of knowledge (usually one markdown file, or one logical unit derived from a registry / table).

### 4.1 Identity

| Field | Rule |
|---|---|
| `path` | Path inside the source, POSIX, no leading slash |
| `stable_key` | Frontmatter `permalink` or `key` if set; otherwise the path without extension |
| `type` | See §4.3 |
| `area` | See §4.4 |
| `title` | Frontmatter `title`, else first heading, else filename |
| `summary` | Frontmatter `summary`, else first paragraph cut at a fixed length (deterministic, no AI) |
| `visibility` | `public` \| `internal` \| `restricted` (default from manifest or adapter) |
| `resolution_key` | Optional; key used by product → company/client → organisation resolution (e.g. `brand.voice`) |

### 4.2 Frontmatter (YAML)

Native and Basic Memory notes use YAML frontmatter. Recognised fields (all optional unless noted):

| Key | Meaning |
|---|---|
| `title` | Display title |
| `summary` | Short summary for lists |
| `permalink` / `key` | Stable id (`stable_key`) |
| `type` | Item type |
| `area` | Area |
| `visibility` | Visibility level |
| `resolution_key` | Resolver match key |
| `decision_date` | When the decision was taken (ISO-8601 date) |
| `effective_date` | When it takes effect |
| `review_date` | When it should be reviewed / expires |
| `status` | `draft` \| `active` \| `deprecated` \| `superseded` (default `active`; `superseded` when only `superseded_by` is set). Readers prefer `active` entries: search lists them first and resolution never lets an inactive entry win over an active one (#218) |
| `superseded_by` | `stable_key` of the entry that replaces this one |
| `tags` | List of strings |

Unknown frontmatter keys are preserved in the indexed `frontmatter` JSON blob.

**Three dates are separate.** Knowledge items never collapse decision / effective / review into one due date. Instituta is not a Gantt: no tickets, assignees, or progress bars in the format.

### 4.3 Types (non-exhaustive)

`note`, `decision`, `rule`, `convention`, `brand`, `glossary`, `plan`, `idea`, `component`, `token-set`, and further types adapters introduce. Unknown types are stored as opaque strings and remain listable/searchable.

### 4.4 Areas

| Area | Intent |
|---|---|
| `rules` | Coding and process rules |
| `decisions` | ADRs / recorded decisions |
| `style` / brand | Voice, visual brand |
| `glossary` | Shared vocabulary |
| `design-system` | Tokens, components, registries |
| `plans` | Plans and roadmaps (not tickets) |
| `connected-systems` | Pointers to other systems (no proxy) |
| `notes` | Catch-all notes |

## 5. Sections and typed blocks

Markdown bodies are split on headings. Each section has:

- `anchor` — slugified heading path (stable within the file)
- `heading`, `level`, `position`
- `body` — raw markdown for that section
- `blocks` — typed structure for agents and the reading view

### 5.1 Block types

| Type | Payload |
|---|---|
| `text` | Markdown / plain text |
| `image` | `path`, **mandatory** `alt`, optional `caption` (agents get path + alt + caption, never the binary). A local `path` is an `embeds` link to the asset (§7), and the reading view shows the picture |
| `table` | Narrative table (rendered as UI table) |
| `data-table` | Reference to a structured table key (§6) |
| `code` | Language + source |

## 6. Structured tables

Source-of-truth tables (colour tokens, app-ui / site-ui registries, lexicons) are **real rows**, not scraped markdown:

- `table_key` — stable within the item
- `columns` — JSON column definitions
- `values` — JSON row values
- `position` — order

Agents query rows through the contract `instituta_table` / `GET /items/{id}/tables/{key}`.

## 7. Assets and sidecars

Images (`png`, `jpg`, `jpeg`, `gif`, `webp`, `avif`, `svg`) and PDFs are **assets** (#311): files people keep in the brain next to their notes (logos, screenshots, brand sheets, specs). Archives (`zip`), fonts (`ttf`, `otf`, `woff`, `woff2`) and Office / OpenDocument files (`docx`, `xlsx`, `pptx`, `doc`, `xls`, `ppt`, `odt`, `ods`, `odp`) are assets too, **download only** (type `file`, #315): listed with their kind, format and size, always served as an attachment, never shown in the page. The brain keeps references next to the knowledge; it is not a file manager (fixed types, a size limit, read-only, no uploads). Each asset becomes an item of its own:

- stable key: its path, extension included (`brand/excubia/mark.svg`, never clashing with `mark.md`);
- title: the file name; type `image`, `document` (PDF) or `file` (download only); area from its top folder, like markdown;
- metadata only: content type, bytes, SHA-256, width × height (raster size or the SVG's `width` / `height` / `viewBox`), alt text and caption. **The bytes never enter the index or reach an agent**; agents read the description (format, size, dimensions, alt, caption, path) and where the file is used.
- The sync keeps a content-addressed copy of the bytes in the app's private storage, so people can view and download the file in the Explorer (inside their perimeter only; SVGs served under a sandboxing CSP).
- Files over **20 MB** are skipped (`asset_too_large`): the brain holds references, not media archives.

A **sidecar** is optional. `<file>.meta.yml` (or `.meta.yaml`) next to the asset adds:

```yaml
title: Excubia mark
alt: The Excubia shield
caption: Dark variant, 512 px
tags: [brand, excubia]
visibility: internal   # optional, as for markdown
area: style            # optional
```

The sidecar is read with its asset, not as an item. A `<file>.md` next to an asset stays a markdown item of its own (for long write-ups about the file).

## 8. Links and the graph

The graph is **derived from the filesystem** at each sync, not hand-edited in the database.

- Plain markdown links and wiki-links become edges.
- Basic Memory `## Relations` lines (`- verb [[Target]]`) set `relation` to the verb.
- Unresolved targets keep `target` text and nullable `to_item_id` until a later sync resolves them.
- Default relation for plain links: `links_to`.

## 9. Adapters

| Adapter | Input | Behaviour |
|---|---|---|
| `instituta` | Native layout + frontmatter | Canonical reader |
| `basic-memory` | Basic Memory markdown | Frontmatter; `## Observations` (`- [category] text #tag`); `## Relations` (`- verb [[Target]]`) |
| `shadcn-registry` | `registry.json` | Components → items / structured rows |
| `markdown` | Generic markdown docs | Sections by heading; deterministic summaries; sidecars |

Adapters produce the same item / section / block / link shapes so the contract DTOs stay adapter-agnostic.

## 10. Citations

Every piece of content returned to a caller carries a citation:

```json
{ "source": "<source id or name>", "path": "<path>", "section": "<anchor or null>", "commit": "<git sha or null>" }
```

List / search / outline responses cite enough to locate the item without bodies.

## 11. Progressive disclosure

- **List / search / outline** never return bodies.
- **Get / get-section / table** return content (typed blocks or rows) plus citation.
- Summaries are always deterministic (frontmatter or truncated first paragraph).

## 12. Validation

`instituta:read --path=<repo> validate` (and the equivalent reader API) checks:

- Parseable frontmatter where present
- Known visibility values
- Image blocks with alt text
- Manifest `format_version` support
- Denylist hits (fail closed)

Validation does not require a database.
