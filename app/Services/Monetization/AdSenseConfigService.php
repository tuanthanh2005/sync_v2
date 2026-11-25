<?php

namespace App\Services\Monetization;

use App\Models\User;
use App\Services\Security\AuditLogger;
use App\Services\System\SystemSettingService;
use Illuminate\Validation\ValidationException;

class AdSenseConfigService
{
    /**
     * Only public content surfaces may ever display Google AdSense.
     * Auth, Security, Wallet, Payments, Admin, and Moderation pages are strictly forbidden.
     *
     * @var list<string>
     */
    public const PERMITTED_PAGE_KEYS = ['home', 'explore', 'community', 'post'];

    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly PremiumSubscriptionService $premiumService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function adminPayload(): array
    {
        $all = $this->settings->all();

        return [
            'feature_adsense' => (bool) ($all['feature_adsense'] ?? false),
            'adsense_enabled' => (bool) ($all['adsense_enabled'] ?? false),
            'adsense_publisher_id' => (string) ($all['adsense_publisher_id'] ?? ''),
            'adsense_slots' => is_array($all['adsense_slots'] ?? null) ? $all['adsense_slots'] : [],
            'adsense_allowed_pages' => is_array($all['adsense_allowed_pages'] ?? null) ? $all['adsense_allowed_pages'] : self::PERMITTED_PAGE_KEYS,
            'adsense_consent_required' => (bool) ($all['adsense_consent_required'] ?? true),
            'adsense_ads_txt_line' => (string) ($all['adsense_ads_txt_line'] ?? ''),
            'permitted_pages' => self::PERMITTED_PAGE_KEYS,
        ];
    }

    /**
     * Strictly validate structured Google AdSense configuration (zero arbitrary HTML/JS allowed).
     *
     * @param  array<string, mixed>  $input
     */
    public function updateConfig(array $input, User $actor, AuditLogger $auditLogger): void
    {
        $publisherId = trim((string) ($input['adsense_publisher_id'] ?? ''));
        if ($publisherId !== '' && preg_match('/^ca-pub-\d{16}$/', $publisherId) !== 1) {
            throw ValidationException::withMessages([
                'adsense_publisher_id' => [__('Publisher ID must follow Google AdSense format ca-pub-XXXXXXXXXXXXXXXX.')],
            ]);
        }

        $rawSlots = is_array($input['adsense_slots'] ?? null) ? $input['adsense_slots'] : [];
        $cleanSlots = [];
        foreach (['sidebar', 'in_feed', 'explore', 'community'] as $slotName) {
            $slotVal = trim((string) ($rawSlots[$slotName] ?? ''));
            if ($slotVal !== '') {
                if (preg_match('/^\d{8,16}$/', $slotVal) !== 1) {
                    throw ValidationException::withMessages([
                        "adsense_slots.{$slotName}" => [__('AdSense slot IDs must be numeric (8-16 digits).')],
                    ]);
                }
                $cleanSlots[$slotName] = $slotVal;
            }
        }

        $rawPages = is_array($input['adsense_allowed_pages'] ?? null) ? $input['adsense_allowed_pages'] : [];
        $allowedPages = array_values(array_intersect(self::PERMITTED_PAGE_KEYS, array_map('strval', $rawPages)));

        $adsTxtLine = trim((string) ($input['adsense_ads_txt_line'] ?? ''));
        if ($adsTxtLine !== '' && preg_match('/^google\.com,\s*pub-\d{16},\s*(DIRECT|RESELLER)(,\s*[a-zA-Z0-9]+)?$/', $adsTxtLine) !== 1) {
            throw ValidationException::withMessages([
                'adsense_ads_txt_line' => [__('Invalid ads.txt entry format. Expected: google.com, pub-XXXXXXXXXXXXXXXX, DIRECT, f08c47fec0942fa0')],
            ]);
        }

        $enabled = (bool) ($input['adsense_enabled'] ?? false);
        if ($enabled && $publisherId === '') {
            throw ValidationException::withMessages([
                'adsense_publisher_id' => [__('A valid Publisher ID is required before enabling AdSense.')],
            ]);
        }

        $this->settings->updateMany([
            'feature_adsense' => $enabled,
            'adsense_enabled' => $enabled,
            'adsense_publisher_id' => $publisherId,
            'adsense_slots' => $cleanSlots,
            'adsense_allowed_pages' => $allowedPages,
            'adsense_consent_required' => (bool) ($input['adsense_consent_required'] ?? true),
            'adsense_ads_txt_line' => $adsTxtLine,
        ], $actor, $auditLogger);
    }

    /**
     * Resolve safe AdSense render props for a specific public page key (returns null when disabled/disallowed).
     *
     * @return array{enabled: bool, publisher_id: string, slots: array<string, string>, consent_required: bool}|null
     */
    public function configForPage(string $pageKey, ?User $viewer = null): ?array
    {
        if (! in_array($pageKey, self::PERMITTED_PAGE_KEYS, true)) {
            return null;
        }

        if ($this->premiumService->userAdReductionMode($viewer) === 'ad_free') {
            return null;
        }

        $all = $this->settings->all();
        $enabled = (bool) ($all['feature_adsense'] ?? false) && (bool) ($all['adsense_enabled'] ?? false);
        $publisherId = trim((string) ($all['adsense_publisher_id'] ?? ''));

        if (! $enabled || $publisherId === '' || preg_match('/^ca-pub-\d{16}$/', $publisherId) !== 1) {
            return null;
        }

        $allowedPages = is_array($all['adsense_allowed_pages'] ?? null) ? $all['adsense_allowed_pages'] : [];
        if (! in_array($pageKey, $allowedPages, true)) {
            return null;
        }

        $slots = is_array($all['adsense_slots'] ?? null) ? $all['adsense_slots'] : [];
        /** @var array<string, string> $stringSlots */
        $stringSlots = [];
        foreach ($slots as $k => $v) {
            if (is_string($k) && is_string($v) && preg_match('/^\d{8,16}$/', $v) === 1) {
                $stringSlots[$k] = $v;
            }
        }

        return [
            'enabled' => true,
            'publisher_id' => $publisherId,
            'slots' => $stringSlots,
            'consent_required' => (bool) ($all['adsense_consent_required'] ?? true),
        ];
    }

    public function adsTxtContent(): ?string
    {
        $line = trim((string) $this->settings->get('adsense_ads_txt_line', ''));
        if ($line !== '') {
            return $line."\n";
        }

        $publisherId = trim((string) $this->settings->get('adsense_publisher_id', ''));
        if (preg_match('/^ca-(pub-\d{16})$/', $publisherId, $matches) === 1) {
            return "google.com, {$matches[1]}, DIRECT, f08c47fec0942fa0\n";
        }

        return null;
    }
}
