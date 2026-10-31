# LBC integration preparation

This packet prepares Battlefront's LBC work. Public LBC pages describe advertised service flows; they do not establish Battlefront's merchant API contract or entitlement. An explicitly approved internal Battlefront simulation may use synthetic local behavior without that contract, but cannot claim LBC compatibility. Provider-compatible simulations, sandbox operations, and production work each require verified evidence and their own authorization. See the [official source audit](api-sources.md) and [capability matrix](capability-matrix.md), checked 2026-10-24.

## Start with the relevant guidance

Read this README for every LBC task, then select references by task:

| Task                                     | Read                                                                                                                                                                                                          |
| ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Official capability/API research         | [API source audit](api-sources.md), [capability matrix](capability-matrix.md), [open decisions](open-questions.md)                                                                                            |
| Domain mapping or system design          | [scope](integration-scope.md), [architecture](architecture.md), [lifecycle](shipping-lifecycle.md), [payment coordination](payment-coordination.md), [open decisions](open-questions.md)                      |
| Local simulation                         | [mock development](mock-development.md), [architecture](architecture.md), [lifecycle](shipping-lifecycle.md), [security](security.md), and relevant current `.ai/rules`                                       |
| Security review                          | [security](security.md), [mock development](mock-development.md), [payment coordination](payment-coordination.md), and relevant current `.ai/rules`                                                           |
| Tests                                    | [mock development](mock-development.md), [lifecycle](shipping-lifecycle.md), [security](security.md), and adjacent tests; read the [sandbox checklist](sandbox-checklist.md) only for authorized sandbox work |
| Sandbox readiness or production proposal | [sandbox checklist](sandbox-checklist.md), [open decisions](open-questions.md), and selected product contract evidence                                                                                        |

Read `docs/CAPSTONE_CONTEXT.md` for changes affecting approved project scope or architecture. Read PayMongo references only when the task touches gateway behavior. Current approved `.ai/rules` govern Battlefront behavior; this packet distinguishes requirements from verified implementation.

## Start from LBC's flow

LBC's public sender flow is organized around a shipment transaction: supply sender and recipient details, origin/destination and package information; choose rider pickup or branch drop-off; determine the service/rate and how the shipping fee is settled; book and prepare the package; hand it to the rider or branch; then track it using the LBC-issued shipment identifier. LBC separately offers COD and COP, where LBC collects from the buyer and remits seller proceeds. These paths must not be collapsed into one generic “book shipment” operation.

The portal publicly advertises booking/tracking and has indexed API names/descriptions, but the actual merchant operations and contracts were not readable in this audit. Therefore the flow above is a domain discovery baseline from public LBC materials, not an API sequence. The chosen merchant product may differ and must be verified from authorized endpoint-specific documentation.

## Preparation priorities

1. **Identify the actual LBC product and merchant flow.** Establish whether Battlefront will use a corporate/merchant API, LBC Online, branch transactions, rider pickup, or another contracted offering. Do not assume the public consumer flow is the API contract.
2. **Map LBC shipment inputs and responsibilities.** Capture sender/recipient, location identifiers, package contents/weight/dimensions/value, shipping service, handoff mode, rate/valuation, fee payer, booking/waybill/tracking identifiers, and physical acceptance events. Compare each with existing Battlefront data and identify missing information.
3. **Separate transport from collection and settlement.** Decide whether Battlefront uses ordinary shipping with Battlefront-owned payment handling, LBC COD, LBC COP, or no carrier collection service. LBC COD/COP involve LBC collecting the buyer amount and remitting seller proceeds; this changes payment, reconciliation, and order-completion responsibilities.
4. **Obtain the merchant API contract.** Verify authorization, product entitlement, sandbox, schemas, state/status semantics, rates, serviceability, booking, tracking, cancellation, retries, errors, and any webhook/reconciliation mechanisms from official endpoint-specific documentation.
5. **Map the verified flow into Battlefront.** Only after the product/contract and business choices are approved, map provider events to current orders, shipments, payment states, inventory, notifications, and accounting. Do not assume the existing manual lifecycle already matches LBC's stages.
6. **Approve implementation and test scope separately.** Keep local simulation, sandbox integration, and production as separate stages and approvals. The [sandbox checklist](sandbox-checklist.md) records evidence and test gates.

## Existing system constraints to preserve during mapping

Battlefront currently has one Shipment per order, manual shipment registration and progression, immutable delivery/order snapshots, COD and pickup payment exceptions, and approved PayMongo reconciliation/cancellation requirements; verify implementation before calling them current behavior. These are compatibility constraints to map against the verified LBC flow, not evidence that the integration should simply reuse every existing step unchanged. See [system mapping](architecture.md), [payment coordination](payment-coordination.md), and [open decisions](open-questions.md).

Follow current approved project rules and PayMongo readiness decisions where they govern existing behavior. Current order cancellation stops before `handed_to_lbc`; historical after-handoff wording in `.ai/rules/models-js-pages-orders.md` is superseded by the current order cancellation rule. No checkout, payment, stock, or refund behavior changes are authorized by this preparation.
