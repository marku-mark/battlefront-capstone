# Chatbot stabilization

The working changes improve branch stock searches, compound catalog questions, and multilingual product presentation. PHP validates provider output before review, and product cards use live catalog facts.

- Sensitive messages use the existing safe fallback before catalog routing. Explicit language preferences and neutral follow-ups use identity-bound conversation state.
- The understanding validator checks multipart shape and branch references. Omitted operations remain optional so the current state reducer can infer refinements.
- PHP validates product prices against the product they describe. Existing failure traces record bounded reasons, and branch search diagnostics record filter names and result counts.
- Branch follow-ups retain verified product IDs and applicable filters. Stock claims use Sagay inventory, with published branch information for other locations.
- Configured Algolia discovery uses live SQL to check candidate eligibility and recognized subtypes. Standalone device terms return local matches first. Storefront search keeps its existing behavior.

The full PHP suite completed with four workers. It reported 6,212 passed, 41 skipped, and zero failures. Pint and PHPStan also passed.

See [Gemini-to-PHP handoff](docs/chatbot/GEMINI_HANDOFF_IMPROVEMENTS.md) for the verification scope.
