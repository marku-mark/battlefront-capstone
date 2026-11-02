---
paths:
    - 'app/{Models/User.php,Services/TeamAccessService.php,Http/Controllers/Administration/TeamController.php},database/**,resources/js/pages/Administration/Team.vue'
---

# Pages Administration

## Current administrator sign-in gate

Supersedes the earlier requirement in this file that administrators must change passwords and enroll in 2FA before access. Two-factor authentication is disabled for now, and signed-in active accounts with an assigned administration role can immediately use only the permissions granted to that role.

## Require verified ownership before administrator login

Mandatory email OTP verification supersedes earlier statements that any active assigned administrator may sign in immediately. Team provisioning and role permissions remain unchanged; new real administrators verify through the same credential-confirmed OTP flow before their first login. Preserve already verified administrators and trusted development seeder pre-verification, account conflicts, passwords and idempotency; never enable local/example-address bypasses or mass verification.

## Verify new Team account email before creating the administrator

The approved in-person Team workflow supersedes post-creation verification for web Team creation only. Owner must obtain a session-bound, single-use email proof through inline OTP before TeamAccessService::create may insert a verified administrator; retain their existing role authority. Use the existing cache/atomic locks, shared SMTP OTP delivery, 5-minute codes/5 attempts/60-second resend cooldown, and 10-minute creation proof. Email changes invalidate eligibility. Never accept a client verified flag, create invitation records, switch the creator's session, bypass existing unverified-account recovery, or modify trusted demo/CLI provisioning. All automated mail is faked.
