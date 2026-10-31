# Security and privacy baseline

## Confirmed provider controls

Official webhook docs describe `Paymongo-Signature` with timestamp and mode-specific signatures, calculated as HMAC-SHA256 over `timestamp.raw_request_body` using the endpoint secret. The selected test (`te`) or live (`li`) signature must match the event mode. PayMongo requires HTTPS and retries unsuccessful deliveries; consult `01-official-sources.md` for references.

## Required project controls

- Follow `11-system-aligned-setup.md` for private root `.env` test-key storage, the separate webhook signing secret, future `config/services.php` mapping, existing business/technical permissions, and retained minimal financial history. Never inspect or print actual local secret values to demonstrate setup.
- Keep API secret keys and webhook secrets in server environment/secret management; never commit or render them to Vue/mobile clients, logs, exception pages, screenshots, or docs.
- Use distinct test/live credentials and endpoint secrets. This preparation contains no credentials.
- Verify signature on exact raw request bytes before JSON-driven state changes; use constant-time comparison, enforce sensible timestamp freshness, and reject wrong mode.
- Bind event to known provider session and local order; compare amount/currency/mode and legal local state. Never trust customer return URLs, query strings, or client-supplied payment fields.
- Enforce event ID uniqueness and transactional idempotency. Keep authorization on customer order views and avoid leaking other customers' provider identifiers.
- Use database uniqueness/transactions and verified endpoint-specific provider idempotency to prevent duplicate orders, payable flows/charges, and webhook effects. Before cancellation, query the latest PayMongo state and confirm applicable provider-flow closure; do not release stock on an uncertain result.
- Treat late payment after local order cancellation as a reconciliation exception; do not automatically fulfill it or assume cancellation returned funds. Refunds require separate authorization and provider confirmation.
- Log correlation IDs and sanitized failure reasons, not authorization/signature headers, full payloads, personal details, or secrets.
- Minimize provider payload retention; define retention/deletion and incident handling with project privacy obligations.

## Threats to test/review

Forged or replayed webhook; test/live mix-up; secret leak; wrong-order/amount/currency association; duplicate/out-of-order delivery; race with cancellation/admin action; return URL spoofing; API timeout followed by duplicate create; provider event loss; log/exception leakage; webhook denial-of-service.

## Local webhook exposure

PayMongo cannot reach localhost directly. For authorized test-mode webhook checks, an HTTPS tunnel may expose only the application webhook endpoint. Never expose MySQL, admin routes, local credentials, or debugging consoles through the tunnel. Tunnel use and webhook registration are later test activities requiring explicit approval; neither was performed during preparation.

## Concrete acceptance boundaries

- Verify the signature using configured endpoint mode/secret and constant-time comparison before trusting parsed fields. Enforce bounded body size, required headers, numeric timestamp parsing and authenticated envelope validation. Store a raw-body digest if needed for integrity investigation; never alter raw bytes before verification.
- Define timestamp tolerance and clock-skew monitoring against verified provider redelivery behavior. Do not confuse event occurrence time with signature delivery time or reject valid delayed events solely because the business event is old. Signature freshness does not replace durable event/payment dedupe. Verify secret rotation overlap before adopting multiple-key acceptance.
- Scope any future CSRF exception to the webhook route; it has signature authentication rather than customer session authentication. Customer create/retry/cancel/status routes retain their normal web or mobile authorization. Rate-limit these actions per customer/order without using a public webhook IP allowlist as the authentication boundary.
- Provider URLs, client keys, bank selection and redirect destinations need trust boundaries. Server return URLs must come from approved configuration. Do not accept arbitrary callback URLs, expose checkout URLs in public order APIs, send secrets to a client, or collect bank credentials/OTPs. Use HTTPS provider origins/redirect hosts verified for the selected method; never invent a blanket host allowlist that breaks bank redirects.
- A correctly signed dashboard test event is not automatically a real completed test payment. It must still match a known local flow and expected amount/mode; ambiguous or unmatched paid evidence requires authoritative retrieval. Manual administrator payment-review endpoints cannot approve gateway orders.
- Use the inbox/transition ordering in `05-payment-flow.md`: persist verified input before acknowledgement; complete event processing and payment state together; recover unprocessed inbox entries after worker/enqueue failure. No network request while holding order locks.
- Apply cancellation guards to customer, administrator, scheduled and method-switch paths, including paid-order requests. Review all current local-only cancellation/proof predicates. Never release stock just because a provider ID was not yet saved.
- Define event-data retention, access roles, operator audit fields and redaction before enabling the integration. Exclude API headers, webhook secrets, full billing/card data, checkout/client-key URLs and raw payload dumps from logs. Limit any signed fixture to synthetic data.

See `10-readiness-audit.md` for state decisions, failure ownership and remaining evidence gates. This document is a technical baseline, not a legal/compliance determination or production security certification.
