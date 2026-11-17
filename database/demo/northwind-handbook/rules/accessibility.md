---
title: Accessibility rules
type: rule
resolution_key: rules.accessibility
---

# Accessibility rules

## Baseline

- WCAG 2.2 AA on every page: contrast, focus order, visible focus ring, labels on every control.
- Every image has alt text; decorative images have an empty `alt`.
- Motion respects `prefers-reduced-motion`.

## Checks

- axe runs in the browser test suite; a new violation fails the build.
- Keyboard-only walk-through before each release, recorded in the [[Release process]].
