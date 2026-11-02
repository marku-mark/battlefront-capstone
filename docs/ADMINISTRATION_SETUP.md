# Administration setup and upgrade

These are operator instructions, not commands run automatically by the application.
Review the target environment and database before running them. Keep a database
backup for an existing installation. Never reset or reseed a populated database to
repair account access.

## Fresh local development

After installing dependencies and configuring a new local database and application
key, run:

```powershell
php artisan migrate --no-interaction
php artisan db:seed --no-interaction
```

With `APP_ENV=local` or `testing`, the default seeder creates these missing
demonstration accounts, all with development-only password `password`:

| Role     | Email                 |
| -------- | --------------------- |
| Owner    | `admin@example.com`   |
| Manager  | `manager@example.com` |
| Staff    | `staff@example.com`   |
| Customer | `test@example.com`    |

Change those passwords before sharing the environment. Production and staging
create none of these demonstration accounts. New administration accounts use the
existing role permissions and receive an audit entry when created.

The existing `battlefront:reset-dev` command also calls this seeder, so an
explicit development reset recreates the same accounts alongside its thirty
demonstration customers. That command destroys the target development database;
it is not needed to add missing demo accounts to an existing local database.
After reviewing the target connection, `db:seed` alone creates missing accounts
while preserving existing ones.

Seeding never updates an existing account's name, password, verification state,
activation, or role. In particular, it preserves an existing legacy IT assignment
without granting that account administration access; the IT role is retired and
must be resolved explicitly before the guarded retirement migration can finish.
An existing Owner remains Owner, and an existing Customer using
`admin@example.com` is not promoted. Use explicit provisioning below if no usable
Owner exists.

## Fresh installation without an Owner

After reviewing and applying the normal migrations, use a trusted server terminal:

```powershell
php artisan administration:assign-role owner@your-store.example owner --create --name="Store Owner"
```

Replace the example email and name. Enter and confirm a chosen password at the
hidden prompts; the command uses the application's password rules. There is no
password argument, generated credential, or noninteractive creation mode.

`--create` accepts only Owner, requires an unused email, and refuses if any Owner
account already exists, including an inactive one. It never changes an existing
account. Creation and the audit entry commit together. Only operators with trusted
server/CLI access should execute this command. Use the Owner's Team page for
subsequent account creation and configurable Staff/Manager permissions.

## Upgrade from the previous Administrator setup

Review the additive migrations and apply them through your normal migration
process. Access-role migrations retain accounts, passwords, and existing roles;
they do not automatically elevate legacy Administrators. An Administrator with
no access role cannot enter administration until explicitly assigned.

Identify the trusted existing Administrator email and run:

```powershell
php artisan administration:assign-role trusted-admin@your-store.example owner --no-interaction
```

Replace the example email with the deliberately selected account. The account
must already have the previous `administrator` base role; a Customer cannot be
promoted by this command. The same command accepts `staff` and `manager`
for explicitly assigning existing Administrators. It retains identity, password,
and activation status. It does not reactivate inactive accounts; those require a
separately reviewed account-recovery action.

Both CLI and Team changes protect the last active Owner. Access changes revoke
sessions, API tokens, and remembered login credentials. CLI assignments record
`team.role.assigned_by_cli`; initial creation records `team.owner.created_by_cli`.
The audit actor is null for CLI operations, with an explicit reason and access
changes; passwords are never included. Existing accounts must sign in again after
their access changes.

Owner retains business administration, Team management, permission management, and audit export. Manager and Staff permissions remain unchanged.

## Operating safeguards

- Only Sagay City has operational stock, orders, and sales. Other branches remain
  published reference information. Store navigation opens management only with
  `branches.manage`; otherwise it opens the read-only directory.
- Archive is the existing recoverable catalog removal, not permanent deletion.
  Stock and unfinished work block product archival; deactivation remains available.
  Historical purchases, movements, media, and audit records are retained. Categories
  with any products, including archived products, cannot be archived. Restoration
  returns records inactive and requires a live product category.
- Opening stock and later manual corrections use stock requests. A different
  authorized reviewer must approve them before quantities change. Provision a
  second appropriate team account for this separation; the first Owner cannot
  approve their own request. Checkout, cancellation, and approved returns keep
  their existing automatic movements. Sellable and quarantined stock stay separate.
- New purchases retain the checkout product name as well as recorded prices and
  quantities. Existing rows have no fabricated name snapshot and use their retained
  product name. The additive migration does not backfill or rewrite history; rollback
  refuses to discard recorded names. Apply it before deploying code that reads it.
- Staff reports use the handler saved on the completed sale, exclude delivery fees,
  and retain unattributed historical sales. Verified refunds use payment dates and
  the original sale's handler; the displayed balance is product sales less those
  verified refunds, not a payroll or commission calculation.

## Existing authentication behavior

Fortify two-factor authentication is disabled. The retained `must_change_password`
flag does not enforce a first-login password-change gate; the Security settings
clear it when a password is changed. Administrators need a supported role and
verified email before using administration. New accounts created through Team
Management complete email OTP inline before insertion and are created verified;
an unverified administrator provisioned outside that flow uses the existing
credential-confirmed OTP step before login. No mandatory password-change or 2FA
enrollment flow is introduced. Operators remain responsible for selecting and
safeguarding privileged credentials.

## Retiring legacy IT assignments

Only Owner, Manager, and Staff are supported. Deploy the application access denial before running the retirement migration. Legacy IT assignments cannot access administration even while the migration remains blocked.

The retirement migration stops without changing data if any account still references the IT role, including inactive accounts. Review those accounts and obtain an explicit operator decision for each assignment before retrying. Deactivation alone does not remove the reference. Do not delete accounts, reset the database, or automatically promote or reassign them. An explicitly authorized reassignment can use the existing trusted-console command and its normal audit and access-revocation safeguards.

Once no assignments remain, the migration removes only the retired role and its grants. Shared permissions and all audit history are retained. Retirement is forward-only and refuses rollback.
