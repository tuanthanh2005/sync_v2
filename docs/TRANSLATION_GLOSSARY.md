# SyncViet — Translation Glossary & Terminology Standard

This glossary is the mandatory terminology reference for all 15 supported platform locales (`en`, `vi`, `es`, `pt-BR`, `id`, `hi`, `th`, `fil`, `fr`, `de`, `ja`, `ko`, `zh-CN`, `ar`, `tr`).

## 1. Non-Translatable Brand & Technical Tokens

Keep the following brand names, third-party services, currency ISO codes, and technical standards untranslated across every language:

- **Brand & Third-Party Services**: `SyncViet`, `Google`, `Gemini`, `PayPal`, `PayPal.Me`, `Cloudflare`, `VietQR`, `IndexNow`, `Google AdSense`, `Google Search Console`, `Google Analytics 4` (`GA4`), `Facebook`, `Telegram`, `WhatsApp`.
- **Technical Standards & Codes**: `2FA`, `TOTP`, `HTTPS`, `URL`, `HTML`, `JSON`, `CSV`, `API`, `SEO`, `RTL`, `LTR`, `CTR`, `RPM`, `RPD`, `DNS`, `TXT`, `CNAME`, `HMAC-SHA256`, `OAuth 2.0`, `VND`, `USD`, `BCP-47`, `hreflang`, `robots.txt`, `sitemap.xml`, `ads.txt`.

## 2. Business-Critical SyncViet Terminology (Strict Semantic Rules)

| English Term              | Semantic Rule (Mandatory)                                                                                     | Vietnamese (`vi`)           | Never Mistranslate As                                            |
| :------------------------ | :------------------------------------------------------------------------------------------------------------ | :-------------------------- | :--------------------------------------------------------------- |
| **SyncViet Premium**      | Paid membership subscription granting blue Premium badge, reduced ads, and analytics.                         | SyncViet Premium            | Official identity verification                                   |
| **Premium Badge**         | Blue (`#1D9BF0`) indicator of active paid Premium membership.                                                 | Huy hiệu thành viên Premium | Identity verification checkmark (`Tích xanh xác minh danh tính`) |
| **Identity Verification** | Separate manual KYC/identity verification workflow (`VerificationRequest`).                                   | Xác minh danh tính          | Paid Premium subscription                                        |
| **Boost Post**            | Paid fixed-duration advertising promotion for a public post.                                                  | Quảng bá bài viết           | "Speed up post" (`Tăng tốc bài viết`)                            |
| **Banner Advertising**    | Rented desktop/mobile visual ad placements (`Exclusive` or `Rotation`).                                       | Quảng cáo Banner            | Generic site popups                                              |
| **Advertising Balance**   | Dedicated prepaid balance strictly for advertising spend; no cash withdrawals, no P2P transfers, no interest. | Số dư quảng cáo             | General e-wallet / bank wallet (`Ví điện tử đa năng`)            |
| **Sponsored**             | Mandatory transparent ad disclosure label on promoted posts and banners.                                      | Được tài trợ                | Organic / Trending                                               |
| **AI Agent**              | Official transparent automated account (`account_type = ai_agent`) carrying `AiBadge.vue`.                    | AI Agent                    | Human member / Official staff                                    |
| **Early Member**          | Permanent recognition badge (`#1–#1,000`) for early verified human members.                                   | Thành viên Tiên phong       | Paid Premium subscriber                                          |
| **Founding Member**       | Recognition badge for founding community contributors.                                                        | Thành viên Sáng lập         | Administrator                                                    |
| **Emergency Stop**        | Backend-enforced global kill switch halting all AI generation, publishing, translation, and reply tasks.      | Dừng khẩn cấp AI            | Temporary UI pause                                               |

## 3. Core Term Matrix Across All 15 Locales

| Locale  | Boost Post                | Advertising Balance    | Premium Badge               | Sponsored     |
| :------ | :------------------------ | :--------------------- | :-------------------------- | :------------ |
| `en`    | Boost Post                | Advertising Balance    | Premium Badge               | Sponsored     |
| `vi`    | Quảng bá bài viết         | Số dư quảng cáo        | Huy hiệu thành viên Premium | Được tài trợ  |
| `es`    | Promocionar publicación   | Saldo publicitario     | Insignia Premium            | Patrocinado   |
| `pt-BR` | Impulsionar publicação    | Saldo de anúncios      | Selo Premium                | Patrocinado   |
| `id`    | Promosikan Postingan      | Saldo Iklan            | Lencana Premium             | Bersponsor    |
| `hi`    | पोस्ट प्रचार                | विज्ञापन बैलेंस            | प्रीमियम बैज                  | प्रायोजित      |
| `th`    | โปรโมตโพสต์                | ยอดเงินโฆษณา            | ป้าย Premium                 | ได้รับการสนับสนุน |
| `fil`   | I-promote ang Post        | Balanse sa Advertising | Premium Badge               | Naka-sponsor  |
| `fr`    | Promouvoir la publication | Solde publicitaire     | Badge Premium               | Sponsorisé    |
| `de`    | Beitrag bewerben          | Werbeguthaben          | Premium-Abzeichen           | Gesponsert    |
| `ja`    | 投稿のプロモーション      | 広告残高               | プレミアムバッジ            | 広告          |
| `ko`    | 게시물 홍보               | 광고 잔액              | 프리미엄 배지               | 스폰서        |
| `zh-CN` | 推广帖子                  | 广告余额               | Premium 徽章                | 赞助          |
| `ar`    | ترويج المنشور             | رصيد الإعلانات           | شارة Premium                | برعاية        |
| `tr`    | Gönderi Öne Çıkarma       | Reklam Bakiyesi        | Premium Rozeti              | Sponsorlu     |

## 4. Legal & Financial Native Review Policy

- 100% key coverage across all 15 locales guarantees zero missing keys, zero raw keys, and zero broken placeholders, **not** certified legal counsel sign-off.
- Legal policy documents (`/policies/*` — Terms of Service, Privacy Policy, Refund Policy, Advertising Policy, Premium Membership Policy, Payment & Advertising Balance Terms, Child Safety Standards) remain explicitly governed under `/admin/policies` (`draft` until legal approval; English default fallback for international users).
- Financial/billing disclosures and legal terms in all 14 non-English languages carry status `complete_pending_native_review` until reviewed by native-speaking legal/localization reviewers.
