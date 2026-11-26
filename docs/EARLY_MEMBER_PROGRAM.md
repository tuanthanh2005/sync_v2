# SyncViet Early Member, Founding Member & Badge System

## 1. Early Member Program (First 1,000 Real Human Members)

Designed to authentically reward SyncViet's earliest verified human adopters with a permanent numbered milestone badge and optional promotional Premium time.

### Eligibility & Concurrency Guarantees

Handled atomically by `EarlyMemberAndBadgeService::enrollEarlyMemberIfEligible()`:

1. **Strict Human Verification**: Only `account_type = human`, `is_test_account = false`, `status = active`, and `email_verified_at !== null` accounts qualify. AI Agents, System Official, and Test accounts are strictly rejected.
2. **Explicit Activation Guard**: Requires both `growth_settings.early_member_enabled = true` AND the seeded campaign `syncviet-early-members-1000` in `active` status (unless an Administrator performs an audited manual enrollment).
3. **Hard Slot Ceiling (Concurrency-Safe)**:
    - Locks `growth_campaigns` row and `early_members` max sequence (`lockForUpdate()`) inside `DB::transaction()`.
    - Enforces `min(early_member_max_slots, campaign.max_participants)` (default `1,000`).
    - Database constraints `UNIQUE(user_id)` and `UNIQUE(member_number)` guarantee zero duplicates under concurrent traffic.
4. **Non-Reusable Member Numbers**:
    - `$nextNumber = (int) EarlyMember::max('member_number') + 1`.
    - If Early Member `#42` is later revoked for abuse, `#42` is **never reassigned** to another member, preserving historical authenticity.
5. **Promotional Reward**: Grants configurable promotional Premium days (default `30` days, `source = 'early_member_reward'`) stacked cleanly via `PremiumSubscriptionService::grantPromotionalPremium()` and revocable without touching paid Premium time.

## 2. Founding Member Program Readiness

- Pre-seeded campaign `syncviet-founding-members` (`status = draft`) and settings (`founding_member_enabled = false`, `founding_member_max_slots = 100`).
- Supports manual or future automated issuance of the permanent `FoundingMember` (`emerald` crown) badge via `/admin/growth/campaigns`.

## 3. Strict Visual Separation of Member Badges (`MemberBadges.vue`)

SyncViet strictly separates **Identity / Trust / Paid Membership / AI Disclosure / Growth Recognition** so users are never misled:

| Badge                   | Visual Style                       | Meaning                                | Component          |
| :---------------------- | :--------------------------------- | :------------------------------------- | :----------------- |
| **SyncViet Premium**    | `#1D9BF0` Solid Blue Badge         | Paid or Promotional Premium Membership | `PremiumBadge.vue` |
| **Official AI Agent**   | Violet/Indigo `AI` Disclosure Pill | Transparent Automated AI Assistant     | `AiBadge.vue`      |
| **Early Member**        | Amber Award Pill (`#1`–`#1000`)    | First 1,000 Verified Human Adopters    | `MemberBadges.vue` |
| **Founding Member**     | Emerald Crown Pill                 | Recognized Founding Contributor        | `MemberBadges.vue` |
| **Referral Ambassador** | Indigo Gift Pill                   | Verified Community Growth Advocate     | `MemberBadges.vue` |
| **Community Builder**   | Sky Users Pill                     | Active Community Organizer             | `MemberBadges.vue` |
| **Official Account**    | Indigo Shield Check                | Official SyncViet Team Account         | `MemberBadges.vue` |

### Zero N+1 Performance

`EarlyMemberAndBadgeService::activeBadgesForUsers(array $userIds)` batch-loads all active non-revoked, non-expired badges in a single query inside `FeedService`, `ProfileService`, and `HandleInertiaRequests`.

## 4. Value-Driven Achievement System

Seeded in `achievements` (`GrowthSeeder`):

- `verified-human`: Verify email address
- `onboarding-complete`: Complete personalized topic & community onboarding
- `community-explorer`: Join first public community
- `first-contribution`: Publish first approved post
- `helpful-advocate`: Refer first qualified human member

Achievements reward genuine participation and quality — **never raw comment/post spam volume**.
