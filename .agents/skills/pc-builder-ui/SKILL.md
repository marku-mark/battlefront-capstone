---
name: pc-builder-ui
description: Design or implement an approved customer PC Builder using Battlefront’s Vue, Inertia, branding, and accessibility conventions.
---

# Purpose

Create a clear, responsive component-selection experience that displays server-owned prices, stock, and evidence-backed compatibility.

## Preconditions

- Read `AGENTS.md`, `CAPSTONE_CONTEXT.md`, and relevant `.ai/rules`.
- For implementation, activate the applicable Battlefront branding, frontend, Inertia Vue, Tailwind, Wayfinder, and testing skills.
- Review current catalog/cart/wishlist patterns and current public EasyPC references when comparison is requested.
- Confirm explicit implementation approval before changing application files.

## Workflow

1. Define Simple and Advanced mode tasks, selection order, required components, and incomplete states.
2. Plan component search/filter, current price and stock display, selection summary, and per-rule compatibility explanations.
3. Plan save/load/clear and cart handoff around customer ownership and server validation.
4. Design responsive layouts, keyboard operation, labels, focus behavior, semantic status announcements, empty/loading/error states, and contrast.
5. Reuse existing Vue/Inertia components and named Wayfinder routes; avoid a separate SPA/API pattern for ordinary web interactions.
6. Prepare focused UI and accessibility scenarios before implementation.

## Expected output

Return page/component flow, required server props/actions, state transitions, responsive/accessibility criteria, and meaningful test scenarios. For approved implementation, report changed files and observed verification.

## Safety and validation

Never treat client-provided prices, stock, or compatibility as authority. Do not copy EasyPC assets or assert unverified features. Do not add frameworks/dependencies or alter unrelated UI. Validate against repository conventions and assistive input behavior.

## References

- `.agents/skills/battlefront-branding/SKILL.md`
- `.agents/skills/battlefront-frontend-design/SKILL.md`
- `.agents/skills/inertia-vue-development/SKILL.md`
- `.agents/skills/tailwindcss-development/SKILL.md`
- `.agents/skills/wayfinder-development/SKILL.md`
- `.agents/skills/easypc-reference-analysis/SKILL.md`
