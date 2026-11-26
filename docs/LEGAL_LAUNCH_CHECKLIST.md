# SyncViet Legal & Public Launch Compliance Checklist (PHẦN 46)

> **MANDATORY DISCLAIMER**: Passing technical security and automated QA tests does **NOT** constitute legal compliance or grant regulatory permission to operate a commercial social network or process live payments. The Project Owner MUST review and obtain qualified legal/tax advice for every item below prior to Public Launch and prior to accepting real money.

## 1. Public Legal Policy Pages (Currently `draft` -> `404` Publicly by Design)

All 13 policy slots are seeded in `/admin/policies` as `draft` so SyncViet never publishes placeholder or unapproved legal text. Before Public Launch, Owner must author, legally review, and publish:

- [ ] Terms of Service (`terms`)
- [ ] Privacy Policy (`privacy`) — including international data transfers, retention, cookies, and deletion rights
- [ ] Community Guidelines (`community-guidelines`)
- [ ] Advertising Policy (`advertising-policy`)
- [ ] Refund & Cancellation Policy (`refund-policy`)
- [ ] SyncViet Premium Terms (`premium-terms`)
- [ ] Referral & Early Member Program Terms (`referral-terms`)
- [ ] AI Content Transparency & Disclosure Policy (`ai-disclosure`)
- [ ] Copyright / DMCA Takedown & Contact Process (`copyright`)
- [ ] Verified Contact / Abuse Report Email & Legal Entity Information

## 2. Technical Compliance Controls Already Built Into Code

- Report Abuse (`POST /reports`), Moderation Queue, Sanctions, and User Appeals (`/admin/appeals`).
- Self-Service Account Deletion (`ProfileController::destroy` — revokes all sessions, stops marketing/retention, removes profile from SEO sitemap, preserves legally required accounting records).
- Granular Notification Opt-Out + Strict Opt-In `marketing_emails = false` + Signed One-Click Unsubscribe (`/growth/unsubscribe/...`).
- Clear visual separation of `AiBadge.vue`, `#1D9BF0` `PremiumBadge.vue`, `MemberBadges.vue`, and Identity Verification.

## 3. Regulatory & Legal Obligations Requiring Owner / Legal Counsel Verification

- [ ] **Vietnam Social Network Licensing & Decree Compliance**: Verify licensing / notification requirements with the Ministry of Information and Communications (MIC / Bộ TT&TT) under Vietnam's prevailing decrees governing social networks (`mạng xã hội`), UGC moderation SLAs, real-user verification, and local data storage obligations (Decree 147/2024/NĐ-CP & Personal Data Protection Decree 13/2023/NĐ-CP).
- [ ] **Business Registration, E-Commerce & Tax / Invoicing**: Confirm business entity registration, e-commerce notification (Bộ Công Thương if applicable), VAT/electronic invoicing (`hóa đơn điện tử`) before activating live VietQR/Bank Transfer/PayPal payments.
- [ ] **International Privacy Laws (GDPR / UK GDPR / CCPA / PDPA)**: Confirm lawful basis, Cookie/GA4/AdSense Consent Management banner, Data Protection Officer / contact details, and age-gating / minor protection policy (`13+`/`16+` depending on jurisdiction).
- [ ] **Consumer Protection for Digital Subscriptions & Promotions**: Confirm refund windows, tax display, promotional giveaway rules (`chương trình khuyến mại` notification if required in Vietnam) before enabling `referral_rewards_auto_grant` or `early_member_enabled`.
- [ ] **Google AdSense & Mobile App Store Policies**: Confirm AdSense policy compliance before toggling `feature_adsense = true`, and review Apple App Store / Google Play IAP rules (`PlatformCapabilityService`) before wrapping SyncViet into native store apps.
