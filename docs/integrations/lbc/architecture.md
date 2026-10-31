# LBC flow and Battlefront system mapping

Status: preparation only. The LBC stages below are derived from public LBC service descriptions, not an accessible merchant API contract. They do not define implementation interfaces or schemas. An approved internal simulation may use synthetic Battlefront-only behavior; it cannot claim provider compatibility. See [official source evidence](api-sources.md).

## LBC-led shipment flow to verify

The public LBC sender flow suggests these business stages. Exact ordering and required fields must be verified for the selected merchant product:

1. Select the appropriate LBC product and service for the shipment.
2. Supply shipper/recipient details, origin/destination, package details, and any declared value or valuation inputs.
3. Determine serviceability and calculate an LBC rate/fee; choose rider pickup or branch drop-off.
4. Select how the sender pays LBC's shipping charges, then submit/confirm the booking.
5. Receive and retain the identifiers LBC actually returns. A booking reference, tracking number, and waybill may be distinct; public channels do not establish one universal lifecycle.
6. Prepare the package and record the physical handoff/acceptance when the rider or branch receives it.
7. Obtain tracking progress from LBC's documented tracking channel and map only verified provider states into Battlefront's presentation.
8. If LBC COD/COP is selected, separately handle LBC's buyer collection and seller remittance/reconciliation process.

This is a preparation sequence, not an API call order. Do not design a request/response DTO or claim the stages are supported in a merchant API until official endpoint documentation and entitlement confirm them.

## Mapping against Battlefront

| LBC responsibility/data              | Battlefront preparation question                                                    | Existing constraint or likely gap                                                                                                                                                    |
| ------------------------------------ | ----------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Product and merchant entitlement     | Which contracted LBC service/API applies to Battlefront?                            | Public catalog does not prove access. Need authorized account/product documentation.                                                                                                 |
| Shipper and recipient                | Which verified contact/address fields and location identifiers are required?        | Reuse saved order/shipment address where appropriate; avoid duplicate PII. Confirm whether Battlefront shipper details/config are complete.                                          |
| Package details                      | Where do package count, weight, dimensions, contents, and declared value come from? | Existing shipping handling profile and order items are not a verified LBC parcel specification. Need product data/packing workflow decision.                                         |
| Origin/destination serviceability    | Which LBC address code/branch/serviceability source is authoritative?               | PSGC validation and Battlefront demo routes are not LBC coverage or serviceability.                                                                                                  |
| LBC rate and valuation               | When is the LBC quote obtained, how long is it valid, and who bears any difference? | Existing delivery fee and order total are immutable snapshots; do not silently replace/reprice them. Checkout/rate integration needs separate approval.                              |
| Pickup vs branch drop-off            | Who schedules/executes the handoff and records acceptance?                          | Existing shipment progression is manual and does not represent booking confirmation or scheduled rider pickup exactly.                                                               |
| Booking/tracking/waybill identifiers | Which identifiers exist, when issued, and how are they recovered after timeout?     | Existing Shipment has a tracking reference field; it may not represent all LBC identifiers/provenance.                                                                               |
| Provider tracking and notices        | Which provider statuses/events are authoritative, and how are they reconciled?      | Existing milestones are Battlefront-owned manual milestones; public tracking labels cannot be treated as API enum/event contracts.                                                   |
| LBC COD/COP collection               | Is carrier collection/remittance part of the selected merchant contract?            | Battlefront already records manual COD collection and separately reconciles remittance before payment/sale completion. These records do not verify LBC settlement or API capability. |
| Parcels per order                    | Can one Battlefront Order produce multiple LBC bookings/tracking numbers?           | Existing schema permits one Shipment per Order. LBC public materials advertise single and multiple booking, but scope/contract is not established.                                   |

## Boundary recommendations for future design

- Keep an LBC adapter isolated from Battlefront Order, payment, and shipment models. Map between LBC's verified DTOs/states and internal types; never present internal mock structures as LBC schemas.
- Treat booking confirmation, physical handoff, provider tracking, delivery, buyer payment collection, and seller remittance as distinct events.
- Persist each provider identifier and its type/provenance separately if the contract returns multiple identifiers. Do not overwrite a manual reference or infer tracking from a booking ID.
- Keep provider calls outside database transactions. Use documented idempotency/lookup/reconciliation behavior to resolve timeouts; do not assume safe retry semantics.
- Reuse existing authorization and order/stock/payment invariants only after mapping the actual LBC stages. A provider event must not automatically verify Battlefront payment unless a separately approved, reconciled settlement contract supports that behavior.

## Existing Battlefront boundaries

Battlefront currently has one Shipment per Order, manual registration and progression, immutable quote/total snapshots, a configured delivery estimate system, COD and pickup payment exceptions, and approved PayMongo cancellation requirements; verify implementation before describing them as current behavior. These are system constraints; they do not prove the public LBC flow fits without changes. Any change to checkout, persisted package data, shipment cardinality, payment/settlement, inventory, sales, or cancellation requires separate design and approval. Follow the current cancellation cutoff before `handed_to_lbc`; an older `.ai/rules/models-js-pages-orders.md` statement conflicts and needs separate maintenance.
