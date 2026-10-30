---
name: pc-hardware-specifications
description: Define, import, normalize, or validate PC component specifications using verifiable manufacturer or standards evidence.
---

# Purpose

Create precise, sourced component facts that a deterministic compatibility system can evaluate. Exact product variant and revision matter.

## Preconditions

- Identify exact make, model, SKU/part number, revision, and relevant market variant.
- Read project scope and `docs/CAPSTONE_CONTEXT.md` for planned Battlefront use.
- Obtain authoritative technical evidence before asserting a specification.

## Workflow

1. Locate manufacturer manuals, CPU support lists, QVLs, product specification pages, or relevant standards.
2. Record source URL, document/section, access or verification date, product revision, value, unit, and confidence/review state.
3. Normalize units while preserving source value and provenance; keep unavailable or conflicting data explicitly unknown.
4. Cover CPU socket, chipset and BIOS support; RAM generation, form, capacity and population; GPU dimensions, slots and power connectors; PSU output and connectors; case clearances; cooler socket/mounting/height; storage interfaces and shared resources; expansion slots.
5. Identify configuration-dependent limits such as radiator/fan placement, cooler/RAM interference, PSU-specific modular cables, and M.2/SATA lane sharing.
6. Hand sourced facts and unresolved conflicts to the compatibility skill; never turn a spec catalog into an implicit pass result.

## Expected output

Return a normalized specification table with exact identity, value/unit, source and section, verification date, status, and limitations. Include missing and contradictory fields.

## Safety and validation

Do not infer specifications from product names, images, generative output, socket alone, connector appearance, or another SKU revision. Do not import or overwrite catalog data without explicit implementation authorization and review. Validate units and variant matching against the linked primary source.

## References

- Intel platform support: https://www.intel.com/content/www/us/en/support/articles/000092149/processors.html
- ASUS memory QVL guidance: https://www.asus.com/support/faq/1043883/
- Noctua component compatibility: https://www.noctua.at/en/compatibility/by-components/cpus
- Corsair PSU cable compatibility: https://help.corsair.com/hc/en-us/articles/360025611351-PSU-Cable-Compatibility
- MSI storage behavior: https://www.msi.com/support/technical_details/MB_Storage_Detection
- Verify current manufacturer documentation for each exact component before use.
