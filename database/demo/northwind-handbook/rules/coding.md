---
title: Coding rules
type: rule
resolution_key: rules.coding
decision_date: 2026-09-02
---

# Coding rules

How every Northwind project is written, unless a client brain says otherwise.

## Stack

- Laravel 13 with Inertia and React 19 for web apps; TypeScript in strict mode.
- Tailwind CSS 4 with the design tokens of [[Northwind UI kit]]; no hard-coded colours.
- Pest for tests; every bug fix lands with the test that would have caught it.

## Reviews

- Every change goes through a pull request reviewed against [[Code review checklist]].
- Accessibility follows [[Accessibility rules]] from the first commit, not as a final pass.

## Releases

- Releases follow the [[Release process]]: a tag, release notes, and a staged rollout.
