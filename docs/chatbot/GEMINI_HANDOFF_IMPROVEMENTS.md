# Gemini-to-PHP handoff

ChatbotAiAdapter validates structured model output with the existing PHP rules. It leaves omitted state operations to ChatbotIntentState, checks each multipart query before retrieval, and uses the existing provider budget and failure trace. A request can contain up to two searches and eligible public turns share the existing three-call budget.

ChatbotGroundedResponse builds bounded evidence from current application facts. PHP checks cited evidence, price attribution, compatibility verdicts, and ranking scope before model review. A rejection returns the application's factual fallback. Product cards display system-controlled links, price, and stock, while the presentation keeps reviewed explanations and removes repeated product details.

Branch retrieval carries explicit filters and verified product identifiers through identity-bound follow-ups. It requeries MySQL when the customer changes the branch or filters. Sagay supplies live inventory. Other branches supply published contact information with an unknown stock status.

## Verification

Automated cases use provider fakes and exercise web and mobile sensitive-message handling, conversation language, multipart validation, grounded prices, branch refinements, and indexed discovery with SQL fallback. All 196 focused tests passed. The full suite ran with four workers and reported 6,212 passed, 41 skipped, and zero failures. Pint and PHPStan passed.
