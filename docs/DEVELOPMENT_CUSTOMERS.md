# Local demonstration customers

`DevelopmentCustomerSeeder` creates 30 fictional Filipino customer accounts in
`local` or `testing` only. Their reserved emails are
`demo.customer01@example.test` through `demo.customer30@example.test`; all use
the shared password `customer123`. These are demonstration identities, not client
records. The trusted development seeder pre-verifies these accounts, so they use
the normal sign-in form without OTP. Public customer registration still requires
the application's email OTP flow before authenticated access.

To add the accounts without resetting existing data:

```shell
php artisan db:seed --class=DevelopmentCustomerSeeder --no-interaction
```

Reruns preserve matching accounts and create missing roster members. Unexpected
changes to a reserved email, name, role, password, or verification status stop the
entire seeder without changing accounts. Profile preferences and addresses remain
editable and are preserved. The seeder creates no purchases or browsing history.

Optional historical sales use this same roster. After reviewing and applying the
schema-only `development_fixture_key` migration and importing the selected catalog
products, generate history without resetting the database:

```shell
php artisan db:seed --class=DevelopmentHistoricalSalesSeeder --no-interaction
```

The historical seeder ensures all 30 accounts exist, then distributes completed
orders deterministically using the calendar month and order slot. Every customer
receives history; recipients use their customer names. Private, unique V4 fixture
keys identify generated orders without exposing a generic label in customer history.
The 48 completed months end at the current quarter's start and cover 15 existing
imported products with 4–7 orders per month. Demand, purchased quantities and
commercial snapshots retain their existing synthetic patterns. Sales amounts record
product subtotal only; payable order totals also include saved delivery fees.

Reruns retain existing fixture ownership, prices and delivery snapshots, even after
catalog/configuration changes. They preserve all ordinary customer transactions,
account preferences, current inventory and imported catalog data. Quarter rollover
adds missing orders for the new window without deleting older history; forecasting
coverage declares only the current 48-month window. No browsing events, payment
proofs, carrier milestones or notifications are generated.

`historical-sales@example.test` is no longer created or required. An existing
account is retained. Earlier quarterly/DEVHIST40 or V1–V3 historical fixtures stop
seeding before accounts, transactions or coverage are changed. Damaged V4 fixtures
also stop without repairing or reassigning records; their coverage is invalidated.
Any cleanup, migration of historical records or database recreation requires separate
approval, development-only execution and ownership validation. The schema migration
does not backfill records and cannot be rolled back while fixture keys exist.

`tests/LegacyQuarterlySalesFixtures.php` remains a guarded test-only dataset for
quarterly product/category aggregation and legacy-history rejection regressions.
Its old account and products intentionally represent the incompatible legacy format;
it cannot run in local or production environments or clear an already-used identity.

Both development reset variants include these accounts automatically:

```shell
php artisan battlefront:reset-dev
php artisan battlefront:reset-dev --with-sales-history
```

**A reset deletes the development database tables.** Run it manually only when
you intend to recreate local data and have the existing verified catalog inputs.
It restores the original demo identities and credentials. With sales history,
those same 30 customers receive historical orders after catalog import; there is
no additional historical-sales account. Administrator/test accounts retain their
existing seeding conventions. Ordinary `DatabaseSeeder` does not create this roster.

Keep this shared credential confined to local/testing. Never seed these accounts
in production, publish the credential on application pages, or log it.
