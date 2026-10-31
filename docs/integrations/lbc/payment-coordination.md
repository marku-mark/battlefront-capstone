# LBC shipping, collection, and Battlefront payment coordination

LBC transport charges, Battlefront customer payment, LBC COD/COP buyer collection, and LBC remittance to Battlefront are separate financial events. Public LBC pages describe sender-paid shipping charges and, for COD/COP, collection from the buyer followed by seller remittance. Those pages do not establish a Battlefront merchant API or settlement contract; see [official source evidence](api-sources.md).

## Payment streams to model separately

| Stream                                  | Meaning                                                                                      | Evidence needed before integration                                                                                                                                                              |
| --------------------------------------- | -------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Customer pays Battlefront               | Battlefront order payment through its current methods and approved gateway/manual workflows. | Existing payment evidence and order policy remain authoritative.                                                                                                                                |
| Battlefront pays LBC shipping/valuation | Sender settles carrier charge through the selected LBC product's flow.                       | Merchant rate, fee/valuation, payer, timing, payment method, invoice/reference, and reconciliation contract. Do not assume the current saved customer delivery fee equals the final LBC charge. |
| Buyer pays LBC COD/COP                  | LBC collects an amount from the buyer at home delivery (COD) or branch pickup (COP).         | Separate approval of service, amount source, collection evidence, failed/partial collection handling, and integration availability.                                                             |
| LBC remits collected funds              | LBC pays proceeds to Battlefront by the contracted settlement method.                        | Merchant agreement, payout schedule/fees, settlement reference/report/API, reconciliation and dispute process. Delivery alone is not settlement proof.                                          |

## Battlefront boundaries

Battlefront's current COD workflow remains pending through delivery. An assigned administrator records collection of the saved order total, then separately reconciles full remittance with a reference; only remittance verification marks payment verified, completes the order, and records the sale. These are Battlefront's manual records and do not prove LBC performed a contracted COD/COP settlement. Any carrier collection/reconciliation integration still needs verified merchant evidence and separate approval. LBC COP remains distinct from Battlefront pickup, which has no Shipment.

PayMongo requirements and implemented behavior are separate claims: consult approved project rules and the PayMongo readiness packet, then verify implementation before describing a safeguard as current behavior. LBC booking, tracking, cancellation, or remittance must not create PayMongo evidence or close a PayMongo flow. For eligible PayMongo-origin order cancellation, the approved requirement is latest-state reconciliation and confirmed applicable-flow closure before stock restoration. A carrier cancellation is not PayMongo closure or a refund.

Do not change order totals or delivery-fee snapshots after placement based on a later LBC quote/charge. Decide the checkout rate source, quote validity, variance ownership, and customer disclosure before any checkout/rate integration; that is separate scope. The default to absorb PayMongo fees remains governed by the approved PayMongo setup.

Until the product, charge flow, and collection/remittance evidence are verified and approved, preparation must not recommend that LBC COD/COP be used for Battlefront orders. See [the decision register](open-questions.md).
