---
paths:
  - 'app/Repositories/Catalog/**'
---

# App Repositories Catalog

## Keep Algolia matching separate from live catalog eligibility
The approved integration uses Algolia only for complete sets of up to 1,000 matching IDs; MySQL applies current availability, filters, sorting and pagination before presentation. On provider errors or incomplete/oversized results, use the existing SQL text predicate for the entire request; successful zero hits remain empty. Keep recommendation/chatbot context matching database-backed. Index only public product text; indexing is independent of activation/stock and uses after-commit ID jobs that reload current state.

## Resolve CPU searches to the Processor category
A complete search query equal to CPU, ignoring case and surrounding whitespace, means the Processor category. Resolve it through the shared live database catalog query before Algolia text matching so web/mobile and provider-outage behavior agree; retain all availability, other filters, sorting and pagination constraints. Do not match unrelated CPU accessories merely because their names contain CPU.

## Resolve common part aliases before provider matching
CatalogSearchIntent extends the CPU-only rule with common category aliases and longer-prefix precedence (CPU cooler resolves to Cooling Components, not Processor). Broad category terms remove only the matched prefix; specific types retain canonical text (SSD/HDD/NVMe), and brand/capacity suffixes remain. Apply category eligibility and existing filters before pagination; subtype text goes through Algolia or all-term SQL fallback. Match whole prefix words only and leave unrelated model-name searches unchanged.

## Expand search aliases against the whole stocked catalog and correct conservatively
Include the actual 27 catalog categories, including CCTV, power accessories, printer consumables, bags/stands, bundles, cleaning, DIY electronics, furniture, office supplies, lighting, vending and services. Longer matching phrases take precedence; protect short acronyms/numeric tokens. Resolve unambiguous one-edit typos in longer alias words and adjacent transpositions in alphabetic words of 4+ characters; ties with differing intent fall through to normal search. Preserve specific types/capacities and avoid equating M.2 with NVMe or SSD with HDD. Generic adapter/USB queries remain unrestricted because those terms span categories.

## Prioritize printer machines for broad printer searches
For broad Printer intent (printer/printers/printing machine and recognized typos), default featured sorting first ranks current catalog machine titles beginning 'PRINTER ' ahead of ink and 'Printer Cable' titles. Do not exclude machines merely because their title says INK TANK. Keep consumable/cable-specific queries unchanged, apply ordering before pagination, and preserve explicit price sorting.

## Handle phrase intent and prioritize primary devices across mixed categories
Search intent now recognizes known part phrases anywhere after bounded polite-prefix cleanup, retains brand/capacity terms, normalizes spaced units (1 TB), and protects unknown 'for' accessory relationships from incorrect category inference. Allow up to two typo edits per alias phrase (two within a word only for alphabetic words of 10+ characters); short/numeric tokens remain protected. Default search ordering demotes verified accessory title patterns in mixed Printer/Storage/CCTV/Networking/Peripherals/Monitor/Cooling groups and UPS/AVR results, before pagination; explicit accessory intent and price sorting take precedence. Storage intent also includes explicitly named HDD/SD-card/Hiksemi-OTG products in CCTV, without recategorizing records or overriding explicit category filters.

## Keep Algolia intent vocabulary and matching conservative
Keep curated product/category aliases and true-equivalence synonym groups together in CatalogSearchIntent; synchronize configured synonyms without replacing unrelated remote records. Do not conflate M.2, NVMe, SSD, and HDD. Search only matching objectIDs from Algolia and keep live availability, filters, sorting, and pagination authoritative in MySQL.

## Keep fallback search literal and token-based
Normalize query whitespace and spaced capacity units before provider/fallback matching while preserving model-code punctuation. SQL fallback requires every term across public product_code/name/brand/description fields, with literal percent/underscore/escape characters. Provider candidates remain authoritative for text matching; MySQL applies live eligibility and catalog filters, not a second SQL text predicate. Preserve current ordering and successful empty provider responses.

## Honor explicit quoted catalog phrases
Paired straight double quotes opt into literal contiguous, case-insensitive phrase matching within one public product_code/name/brand/description field. Keep quoted units, punctuation and alias words literal; skip category intent rewriting for quoted searches. Algolia requests enable only advancedSyntaxFeatures exactPhrase (not minus exclusions). Apply quoted phrase predicates to live SQL before pagination even for provider IDs; this is the explicit exception to provider-only text matching. Unquoted terms remain provider matched or all-term SQL fallback; multiple quoted phrases are ANDed.

## Reuse catalog search for short chatbot discovery
Developer approved chatbot Algolia candidate retrieval for short product discovery after Send. Reuse CatalogSearchIntent and live MySQL eligibility/prices/inventory, bounded to three displayed products. Preserve exact named unavailable-item inquiries and SQL outage fallback; provider zero hits do not trigger broader text matching. Structured presentation supplements the existing text response.
