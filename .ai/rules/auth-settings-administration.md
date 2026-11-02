---
paths:
    - 'app/Models/User.php,config/fortify.php,routes/**,app/Providers/FortifyServiceProvider.php,resources/js/pages/{auth,settings,Administration/Customers}/**,tests/Feature/{Auth,Settings,Administration}/**'
---

# Auth Settings Administration

## Keep Fortify's default email-link verification disabled

Do not enable Fortify's default email-link verification or its MustVerifyEmail workflow. The application has separate custom OTP flows for public customer registration, unverified administrator login, and inline Team account creation; preserve those flows and the authentication/role boundaries. Keep the email-verification column and custom OTP routes in place.

## Require email OTP before web and mobile authentication

The developer-approved mandatory email OTP requirement applies to every unverified account before authenticated access. Public customers verify after registration and receive no session or API token from registration/verification. Owner-created Team administrators verify inline before insertion and are saved verified, so they do not need a second OTP at first login; unverified administrators provisioned outside Team use credential-confirmed OTP during login. Keep default Fortify link verification disabled, reject stale unverified sessions/tokens, and preserve account-bound recovery and deferred guest shopping continuation. Only explicitly trusted demo seeders pre-verify accounts. Use configured synchronous SMTP; never inspect secrets or send real email during implementation/tests without separate authorization.

## Superseded: original-session customer OTP sign-in

The approved web registration refinement supersedes the separate-login step only for a new public customer who completes OTP verification in the original registration context with unchanged account/email/password. Consume server-only account/context/credential binding, sign in through the normal web guard without remember-me, rotate the session, and reuse Fortify LoginResponse plus guest cart/recommendation continuation. Registration itself remains unauthenticated; credential-recovery starts clear this eligibility. Existing-account recovery, administrators, and mobile verification continue to require their separate normal login; never issue mobile tokens from verification.

## Isolate registration rate limits from guest activity

Use the named registration limiter (5 attempts/minute/IP) for both Fortify web and API signup. Laravel's unprefixed numeric throttle keys are shared by guest domain/IP, so product activity or OTP requests can otherwise exhaust signup's smaller allowance. Keep OTP issuance/verification budgets and cooldowns independent; do not disable or flush security limits to solve a registration collision.

## Extend customer OTP automatic sign-in to credential-confirmed web login

The approved refinement supersedes the original-registration-only eligibility rule: active web customers may automatically sign in after OTP both from public registration and from a valid email/password login, including a new browser session. Replace and consume a server-only account/email/context/password-fingerprint binding; preserve the submitted login remember choice, with no remember-me at registration. Reuse Fortify LoginResponse and existing cart/intended redirects; guest recommendation transfer still requires its original account-bound registration continuation. Unverified administrators still complete the normal credential-confirmed login flow after OTP; Team-created administrators are already verified. Mobile customers log in after OTP, and verification never issues Sanctum tokens.
