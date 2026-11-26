# SyncViet Referral System Architecture

## 1. Personal Referral Codes (`SV-XXXXXX`)

- Generated lazily on demand via `ReferralService::getOrCreateCode(User $user)`.
- Uses cryptographically secure `random_int()` over a confusion-free uppercase alphanumeric alphabet (`ABCDEFGHJKLMNPQRSTUVWXYZ23456789` — omitting `0/O/1/I`).
- **PII-Free & Non-Sequential**: Never embeds `user_id`, username, email, or incrementing numbers.
- **Strict Human Eligibility**: Rejects `AiAgent`, `SystemOfficial`, and `is_test_account = true` accounts with `ValidationException` / HTTP `403`.
- **Admin Control**: Administrators can disable/re-enable individual `referral_codes` with a logged audit reason in `/admin/growth/referrals`.

## 2. Public Referral Landing & SEO Hygiene (`/r/{code}`)

Handled by `ReferralController::visit()` (`GET /r/{code}`):

1. Validates format `^SV-[A-Z0-9]{6,10}$` and confirms the code and referrer are active human accounts.
2. **SEO Protection**: Always responds with `X-Robots-Tag: noindex, nofollow` and redirects (`302`) to the canonical registration path (`/register`) or a sanitized internal `?next=/...` path. Referral links never create duplicate indexable URLs.
3. **Open Redirect Prevention**: `ReferralService::sanitizeInternalReturnPath()` strictly allows relative paths beginning with single `/` (blocking `https://`, `//`, backslashes, and `javascript:`).
4. **Bot & Crawler Filtering**: Skips creating `referral_visits` rows or incrementing `visits_count` for known crawlers (`Googlebot`, `Bingbot`, `curl`, `wget`, empty User-Agent, etc.).
5. **Deduplication**: Computes a daily `visitor_hash` from session seed + date + user-agent slice with `unique(referral_code_id, visitor_hash)` so repeated page refreshes do not inflate visit counters.
6. **First-Touch Cookie**: Sets an `HttpOnly`, `SameSite=Lax`, HTTPS-`Secure` cookie `sv_ref_code` (default TTL: 30 days, configurable 1–90 days) **only if the visitor does not already hold a `sv_ref_code` cookie**.

## 3. Registration Attribution Hooks

Wired seamlessly into both email/password and OAuth registration flows:

- `app/Actions/Fortify/CreateNewUser.php`
- `app/Http/Controllers/Auth/SocialAuthController.php`

### Attribution Rules

- **First-Touch Only**: If `users.referred_by_user_id` is already populated or a `referrals` row exists for `referee_id`, attribution is skipped — no overwriting and no retroactive claiming.
- **Source Priority**: Session key (`syncviet.growth.referral_code`) -> Cookie (`sv_ref_code`) -> explicit code fallback.
- **Immediate Guardrails**: Evaluates `FraudDetectionService::evaluateReferral()`. Hard blocks (self-referral, Gmail alias self-referral, non-human account, direct mutual loop) reject linkage immediately and log `growth.referral.blocked`.
- **Verification Hold Window**: Valid attributions start in `pending` (or `under_review` if risk score ≥ `fraud_manual_review_score`) with `eligible_at = now()->addHours(referral_verification_hours)` (default `72h`).

## 4. Referral Lifecycle & Audit Trail

Every state transition (`pending` -> `under_review` / `qualified` / `rejected` / `rewarded` / `revoked`) appends an immutable audit record to `referral_qualification_events` capturing `from_status`, `to_status`, `reason`, `actor_id`, and `metadata`.

## 5. Privacy-Preserving Member Referral Hub (`/referral`)

- Displays the member's `SV-XXXXXX` code, copyable link, native Web Share / Facebook / X / Telegram / WhatsApp / Email share links, and a locally generated inline SVG QR code (zero third-party QR API calls!).
- Referee list displays **masked handle only** (`@username`) and status — **never exposing referee email addresses, IPs, or private moderation notes**.
