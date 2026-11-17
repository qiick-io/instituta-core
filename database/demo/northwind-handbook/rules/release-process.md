---
title: Release process
type: rule
resolution_key: rules.release
---

# Release process

## Steps

1. Freeze the release branch and run the full test suite.
2. Write release notes in plain language for the client.
3. Tag the release and deploy to staging, then production after the client's sign-off.

## After release

- Watch error rates for 24 hours.
- Record anything surprising as a decision, like [[Staged rollouts for client apps]].
