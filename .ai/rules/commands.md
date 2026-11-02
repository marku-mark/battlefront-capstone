---
paths:
    - 'database/seeders/DevelopmentHistoricalSalesSeeder.php,app/Console/Commands/ResetDevCommand.php'
    - 'database/seeders/DevelopmentCustomerSeeder.php,app/Console/Commands/ResetDevCommand.php'
    - 'database/seeders/DatabaseSeeder.php,app/Console/Commands/ResetDevCommand.php'
---

# Commands

## Keep real-catalog demonstration history separate from operational sales

Historical development seeding references existing imported product codes and must never change current inventory or catalog sources. Real catalog identities do not make generated transactions operational: retain synthetic_development provenance and development_fixture_transactions scope. Detect legacy DEVHIST40 products or owned historical transactions and stop with manual reset instructions; never automatically migrate, delete, or convert that legacy dataset.

## Preserve versioned demonstration ownership across reruns

Varied retail history uses versioned order labels and calendar-based deterministic plans so overlapping months retain their snapshots after quarter rollover or catalog price changes. Validate all candidate identities, items and completed commercial snapshots before pruning only expired owned history. Earlier fixed-basket or unrecognized fixtures stop with manual development-reset instructions; never silently reinterpret their ownership.

## Keep historical payment and delivery evidence explicitly synthetic

Historical demo orders may mix supported pickup/payment methods and online-only delivery. Use existing delivery quote rules for new immutable commercial snapshots and terminal Delivered shipment records; leave payment proofs, tracking references, milestone timestamps and operational ETAs absent. Mark records as synthetic through versioned order labels and delivery assumptions. Never run live checkout, stock, payment, shipment or notification services; earlier fixture versions require manual reset.

## Keep the thirty customer demo identities development-only and immutable on reruns

DevelopmentCustomerSeeder owns only demo.customer01@example.test through demo.customer30@example.test in local/testing and runs by default during battlefront:reset-dev, independently of sales history. Preserve matching accounts unchanged; reject reserved email/name/Customer-role/password/verification conflicts atomically without overwriting credentials or unrelated users. Keep DatabaseSeeder and the separate historical-sales account/transactions unchanged; never create commerce or recommendation activity for this roster.

## Seed a development account for each administration role

The approved demo roster is admin@example.com (Owner), staff@example.com and manager@example.com, plus test@example.com (Customer), all local/testing only. Supersedes the earlier single-administration-demo-account assumption. DatabaseSeeder creates missing identities and audits role assignment only for newly created accounts; never overwrite existing roles, passwords, status, or customer collisions. reset-dev reuses DatabaseSeeder; never execute a real reset or account seeding without explicit authorization.
