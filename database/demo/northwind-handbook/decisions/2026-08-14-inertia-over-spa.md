---
title: Inertia over a separate SPA
type: decision
decision_date: 2026-08-14
resolution_key: decisions.frontend-architecture
---

# Inertia over a separate SPA

## Context

Client apps need server-side routing, authorisation in one place and a fast start for small teams.

## Decision

New web apps use Laravel with Inertia and React instead of a separate API plus SPA.

## Consequences

- One deploy, one auth system; see [[Coding rules]].
- Public APIs, when a client needs them, are added as versioned REST endpoints.
