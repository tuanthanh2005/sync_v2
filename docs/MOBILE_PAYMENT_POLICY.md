# Mobile App Store & Multi-Platform Payment Policy Readiness

## 1. Platform Distinction (`PlatformCapabilityService`)

SyncViet cleanly distinguishes four client runtimes via `X-SyncViet-Platform` (default `web`):

| Platform       | External Web Checkout | Digital Premium / Boost                    | Banner Display | Required Digital Billing                                       |
| :------------- | :-------------------- | :----------------------------------------- | :------------- | :------------------------------------------------------------- |
| `web`          | Allowed               | Allowed                                    | Allowed        | Configured fiat gateways (`vietqr`, `bank_transfer`, `paypal`) |
| `pwa`          | Allowed               | Allowed                                    | Allowed        | Configured fiat gateways (`vietqr`, `bank_transfer`, `paypal`) |
| `android_play` | **Blocked**           | **Blocked** (until Play Billing SDK wired) | Allowed        | `google_play_billing` (`GooglePlayBillingAdapter`)             |
| `ios_appstore` | **Blocked**           | **Blocked** (until StoreKit IAP wired)     | Allowed        | `apple_storekit` (`AppleStoreKitAdapter`)                      |

Rules live in `platform_compliance_rules` and can be reviewed/updated in `/admin/monetization/settings` (Tab: `compliance`).

## 2. Native Billing Adapters (Prepared Contracts Only)

- `App\Services\Monetization\Contracts\NativeBillingAdapterInterface`
- `App\Services\Monetization\Adapters\GooglePlayBillingAdapter` (`isConfigured() === false`)
- `App\Services\Monetization\Adapters\AppleStoreKitAdapter` (`isConfigured() === false`)

SyncViet NEVER attempts to bypass Google Play or Apple App Store in-app purchase rules, NEVER hides external payment links inside native shells to circumvent review, and NEVER displays non-functional fake native checkout buttons.
