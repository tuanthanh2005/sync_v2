<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\ValidUploadedImage;
use App\Services\Media\SafeImageUploadService;
use App\Services\Security\AuditLogger;
use App\Services\System\SystemSettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SystemSettingsController extends Controller
{
    public function __construct(
        private readonly SystemSettingService $settingService,
        private readonly AuditLogger $auditLogger,
        private readonly SafeImageUploadService $imageUploadService,
    ) {}

    public function index(): Response
    {
        $settings = $this->settingService->all();
        $disk = (string) config('syncviet.media_disk', 'public');
        $brandingUrls = [];

        foreach (['logo_light_path', 'logo_dark_path', 'favicon_path', 'og_image_path'] as $key) {
            $path = is_string($settings[$key] ?? null) ? trim($settings[$key]) : '';
            $brandingUrls[$key] = $path !== '' ? Storage::disk($disk)->url($path) : null;
        }

        return Inertia::render('admin/Settings/Index', [
            'settings' => $settings,
            'schema' => SystemSettingService::defaults(),
            'brandingUrls' => $brandingUrls,
            'uploadRuntime' => $this->imageUploadService->runtimeLimits(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'settings' => ['required', 'array'],
        ]);

        /** @var array<string, mixed> $values */
        $values = $validated['settings'];

        // Prevent direct arbitrary external URL injection into branding image paths.
        unset($values['logo_light_path'], $values['logo_dark_path'], $values['favicon_path'], $values['og_image_path']);

        $this->settingService->updateMany($values, $actor, $this->auditLogger);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('System settings saved.')]);

        return back();
    }

    public function uploadBrandingAsset(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'asset_key' => ['required', 'string', Rule::in(['logo_light_path', 'logo_dark_path', 'favicon_path', 'og_image_path'])],
            'image' => ['nullable', 'file', new ValidUploadedImage('branding')],
            'remove' => ['sometimes', 'boolean'],
        ]);

        $assetKey = (string) $validated['asset_key'];
        $existingPath = (string) $this->settingService->get($assetKey, '');
        $disk = (string) config('syncviet.media_disk', 'public');

        if (filter_var($validated['remove'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $this->imageUploadService->deleteIfExists($disk, $existingPath);
            $this->settingService->updateMany([$assetKey => ''], $actor, $this->auditLogger);

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Branding image removed.')]);

            return back();
        }

        $file = $request->file('image');
        if ($file === null) {
            return back()->withErrors(['image' => __('Please select an image from your device.')]);
        }

        $context = match ($assetKey) {
            'favicon_path' => 'favicon',
            'og_image_path' => 'og_image',
            default => 'branding',
        };

        $stored = $this->imageUploadService->storeImage($file, 'branding', $context, 'image');
        $this->settingService->updateMany([$assetKey => $stored['path']], $actor, $this->auditLogger);
        $this->imageUploadService->deleteIfExists($disk, $existingPath);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Branding image updated.')]);

        return back();
    }
}
