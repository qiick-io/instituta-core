---
title: Staged rollouts for client apps
type: decision
decision_date: 2026-09-20
---

# Staged rollouts for client apps

## Context

A Friday release of the Fjord Rider App broke sign-in for 4% of riders.

## Decision

Mobile and web releases go to 10% of users first, then 50%, then everyone, at least six hours apart.

## Consequences

The [[Release process]] adds a rollout step; feature flags are required for risky changes.
