# Google AdSense Readiness & Controlled Integration

## 1. Security-First Architecture (Zero Raw JS Injection)

SyncViet provides structured Google AdSense readiness managed by authorized admins at `/admin/monetization/settings` (Tab: `adsense`, permission `adsense.manage`):

- Admins NEVER paste raw `<script>` or arbitrary HTML snippets.
- `AdSenseConfigService::updateConfig()` strictly validates:
    - `adsense_publisher_id`: `/^ca-pub-\d{16}$/`
    - `adsense_slots.*`: numeric `/^\d{8,16}$/`
    - `adsense_ads_txt_line`: `/^google\.com,\s*pub-\d{16},\s*(DIRECT|RESELLER)(,\s*[a-zA-Z0-9]+)?$/`
    - `adsense_allowed_pages`: subset of `['home', 'explore', 'community', 'post']`

## 2. Placement & Policy Guardrails

- **Direct Rented Banners Always Win**: `AdSenseSlot.vue` receives `:has-direct-banner` and automatically hides whenever a direct SyncViet Banner occupies the slot.
- **Strictly Forbidden Surfaces**: AdSense is NEVER emitted on authentication pages, 2FA/security settings, `/wallet`, `/payments`, `/account/premium`, `/admin/*`, or moderated/hidden content.
- **Premium Ad-Free Respect**: Viewers whose active Premium plan has `ad_reduction_mode = 'ad_free'` receive `null` AdSense config from backend.
- **`/ads.txt` Endpoint**: Automatically serves the validated publisher declaration at `https://<domain>/ads.txt` (returns `404` when unconfigured).
