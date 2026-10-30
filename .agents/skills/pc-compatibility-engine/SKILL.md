---
name: pc-compatibility-engine
description: Design, implement when explicitly approved, or test deterministic PC component compatibility with evidence and clear explanations.
---

# Purpose

Evaluate component relationships using versioned, sourced facts and deterministic rules. AI guesses and product-name heuristics cannot establish authoritative compatibility.

## Preconditions

- Read `pc-hardware-specifications/SKILL.md` and gather evidence for exact component variants.
- Establish which checks are required, conditional, or unsupported for the requested build.
- For implementation, confirm explicit developer approval and read Battlefront instructions/rules.

## Workflow

1. Normalize the selected component facts while retaining provenance and unknowns.
2. Evaluate applicable relationships: CPU/socket/chipset/BIOS; RAM generation/capacity/population; GPU slot and configuration-dependent case clearance; PSU capacity/connectors; cooler mount/height/interference; storage protocol, slots and shared lanes; expansion resources.
3. Emit per-rule `compatible`, `warning`, `incompatible`, or `unknown` outcomes with rule/version, affected parts, explanation, evidence, and corrective action where possible.
4. Keep incomplete builds incomplete. Missing required evidence stays unknown; it never becomes compatible through absence of a detected failure.
5. Aggregate outcomes without suppressing any incompatible, warning, unknown, or incomplete result.
6. Produce rule and fixture matrices for boundaries, conflicts, missing evidence, and source revision changes.

## Expected output

Provide rule definitions, required inputs, evidence links, status semantics, human-readable explanations, aggregate behavior, and test cases. For code tasks, identify the approved implementation boundary and validate rules with deterministic tests.

## Safety and validation

Never use an LLM as the compatibility authority. Do not claim universal compatibility when only specified checks passed. Socket match alone does not establish CPU support. Distinguish conditional operation from known failure. Do not publish or persist an unreviewed result as authoritative.

## References

- `pc-hardware-specifications/SKILL.md`
- Manufacturer evidence listed there and exact component manuals/support pages.
