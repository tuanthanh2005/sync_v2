@props(['url'])
@php
    $appName = (string) config('app.name', 'SysNoxis');
    $slogan = (string) config('syncviet.branding.default_slogan', 'Connect Beyond Borders.');
    $version = (string) config('syncviet.branding.asset_version', 'sysnoxis-2');
    $baseUrl = rtrim((string) config('app.url', 'https://sysnoxis.com'), '/');
    $logoUrl = $baseUrl.'/icons/icon-192.png?v='.rawurlencode($version);
    $isCustomFullLogo = false;

    try {
        $branding = app(\App\Services\System\SystemSettingService::class)->brandingPayload();
        if (! empty($branding['logoLightUrl'])) {
            $customUrl = (string) $branding['logoLightUrl'];
            $logoUrl = str_starts_with($customUrl, 'http://') || str_starts_with($customUrl, 'https://')
                ? $customUrl
                : $baseUrl.'/'.ltrim($customUrl, '/');
            $isCustomFullLogo = true;
        }
    } catch (\Throwable) {
        // Fallback safely to internal versioned SysNoxis icon.
    }
@endphp
<tr>
<td class="header" style="padding: 25px 0; text-align: center;">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
@if ($isCustomFullLogo)
<img src="{{ $logoUrl }}" alt="{{ $appName }}" style="height: 36px; max-width: 180px; border: 0; vertical-align: middle;">
@else
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 0 auto; border-collapse: collapse;">
<tr>
<td style="vertical-align: middle; padding-right: 10px;">
<img src="{{ $logoUrl }}" width="36" height="36" alt="{{ $appName }}" style="display: block; width: 36px; height: 36px; border: 0; border-radius: 8px;">
</td>
<td style="vertical-align: middle; text-align: left;">
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 20px; font-weight: 700; line-height: 1.1; color: #0F172A; letter-spacing: -0.02em;">{{ $appName }}</div>
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 11px; font-weight: 500; line-height: 1.3; color: #64748B;">{{ $slogan }}</div>
</td>
</tr>
</table>
@endif
</a>
</td>
</tr>
