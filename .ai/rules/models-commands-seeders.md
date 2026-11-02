---
paths:
    - 'app/{Models/User.php,Services/TeamAccessService.php,Console/Commands/AssignAdministrationRole.php},database/seeders/DatabaseSeeder.php'
---

# Models Commands Seeders

## Provision Owners explicitly and preserve seeded account assignments

Supersedes the earlier admin@example.com-is-IT seeding assumption: only newly created local/testing demo accounts receive Owner; never change any existing account on reseeding, and create no demo users in production/staging. Legacy Administrators remain denied until explicitly assigned through administration:assign-role; --create provisions only the first Owner with hidden password prompts. Web and CLI access changes share last-active-Owner protection and credential revocation. The IT administration role is retired: trusted seeders preserve legacy assignments without granting access, and the retirement migration waits for explicit operator reassignment of every account. Do not enable mandatory password-change or 2FA flows.
