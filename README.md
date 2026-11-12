# Instituta

> **Working name.** "Instituta" is a working name pending legal clearance. Do not use it publicly, and do not register, publish or file anything under it, until the maintainer says the name is cleared. This repository is private.

Instituta is a qiick product: the operating **company brain**. It holds a company's (or a freelancer's, or one person's) brand, coding rules, design systems and shadcn registries, ideas and plans, so that agents and humans query the same content under one contract. It is AI-first, and the human view is first-class too: a map of the whole brain, a reading view, the resolved view per product and a scoped graph. Content is owned by the user: qiick supplies the product, not the corpus. Open source, self-hosted, and later on qiick Cloud.

## One repository

`qiick-io/instituta-core` is the whole product, the same way `externa-core` is for Externa: one self-hosted Laravel app with the MCP server, the REST API, the Artisan CLI and the UI. Inside it, the brain format reader and resolver are a self-contained module, so they could be extracted later. Any extra piece added later gets a name that says what it is (for example `instituta-cli` or `instituta-sdk-php`), never a second "core".

Git is the durable store: a brain is a git repository (or a path in one), and the graph is derived from its files.

## Status

Nothing is built yet. The kickoff prompt for the agent that builds the MVP, with the product summary, the data model, the build plan and the qiick rules, is [`docs/agents/cursor-kickoff.md`](docs/agents/cursor-kickoff.md).

## Licence

[MIT](LICENSE).
