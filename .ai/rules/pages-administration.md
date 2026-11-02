---
paths:
    - 'app/{Models/User.php,Services/TeamAccessService.php,Http/Controllers/Administration/TeamController.php},database/**,resources/js/pages/Administration/Team.vue'
---

# Pages Administration

## Current administrator sign-in gate

Supersedes the earlier requirement in this file that administrators must change passwords and enroll in 2FA before access. Two-factor authentication is disabled for now. After email verification and sign-in, active accounts with a supported administration role can use only the permissions granted to that role.

## Require verified ownership before administrator login

Every unverified administrator must complete email OTP before authenticated access. Administrators provisioned outside Team use credential-confirmed OTP at login; Owner-created Team accounts complete inline OTP before insertion and are saved verified, so no second OTP is needed at first login. Preserve already verified administrators and trusted development seeder pre-verification, account conflicts, passwords and idempotency; never enable local/example-address bypasses or mass verification.

## Verify new Team account email before creating the administrator

The approved in-person Team workflow completes verification before web Team creation. An authorized Owner must obtain a session-bound, single-use email proof through inline OTP before TeamAccessService::create may insert a verified administrator; legacy IT assignments have no Team access. Use the existing cache/atomic locks, shared SMTP OTP delivery, 5-minute codes/5 attempts/60-second resend cooldown, and 10-minute creation proof. Email changes invalidate eligibility. Never accept a client verified flag, create invitation records, switch the creator's session, bypass existing unverified-account recovery, or modify trusted demo/CLI provisioning. All automated mail is faked.
