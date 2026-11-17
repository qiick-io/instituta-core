# Security Policy

## Supported versions

| Version                            | Supported                                                            |
| ---------------------------------- | -------------------------------------------------------------------- |
| 1.x (latest minor)                 | Yes: security fixes ship as a patch release of the latest 1.x minor  |
| 1.x (older minors)                 | No: upgrade to the latest 1.x minor (no breaking changes within 1.x) |
| 1.0.0 betas and release candidates | Until 1.0.0 is released, the latest pre-release only                 |
| 0.x and earlier                    | No                                                                   |

Run the latest release of the 1.x line: the Docker image tag and `composer.json` carry the version, and the sidebar shows it.

How scopes, keys and grants keep tenants apart is in [`docs/SPEC.md`](docs/SPEC.md) (§3, §5) and [`docs/CONTRACTS.md`](docs/CONTRACTS.md).

## Reporting a vulnerability

Please do not open a public issue. Use GitHub's private vulnerability reporting ("Report a vulnerability" on the Security tab of `qiick-io/instituta-core`). We aim to acknowledge reports within 72 hours.

Fixes ship as a patch release of the latest minor, with a security advisory on GitHub and a post on the Qiick blog (https://qiick.io/en/blog/tag/security).

## What leaves the server

Instituta has no telemetry. It reads the sources you register and never writes to them; the brain stays on your server. Data leaves it only where you turn something on:

- **Git sources** (`git`, `github`): Instituta fetches the repository; with a GitHub token it opens pull requests for accepted proposals.
- **Embeddings** (`AI_EMBEDDINGS_ENABLED=false` by default): section text is sent to the embeddings endpoint in `config/ai.php` (a local OpenAI-compatible server by default).
- **The in-app assistant** (`AI_ASSISTANT_ENABLED=false` by default): the questions users type and the brain sections its tools read are sent to the provider in `config/ai.php`.

Agents reach the brain only through the MCP and REST contract, with a key bound to one scope and the same permissions as a person; every call is logged without content. Writes from agents are off unless `INSTITUTA_API_WRITE_ENABLED` / `INSTITUTA_MCP_WRITE_ENABLED` are on, and they always land as proposals a person reviews.

Ingest fails closed: files that look like secrets or personal data (private keys, API tokens, `.env` bodies, passwords in URLs, email addresses, phone numbers) are skipped, and the sync report records only the file and the reason, never the match. Reports showing data leaving the server in any other way are treated as critical.
