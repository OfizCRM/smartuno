<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\StorageManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SystemSettingsController extends Controller
{
    public function index(): Response
    {
        $generalKeys = ['app_name', 'app_tagline', 'support_email', 'primary_color', 'secondary_color', 'font_family'];

        $general = [];
        foreach ($generalKeys as $key) {
            $general[$key] = SystemSetting::get($key, '');
        }

        $logoPath    = SystemSetting::get('app_logo_path');
        $faviconPath = SystemSetting::get('app_favicon_path');

        $logoDisk    = SystemSetting::get('app_logo_disk', 'public');
        $faviconDisk = SystemSetting::get('app_favicon_disk', 'public');
        $sm = app(StorageManager::class);
        $sm->ensureDiskReady($logoDisk);
        $sm->ensureDiskReady($faviconDisk);
        $general['logo_url']    = $logoPath    ? Storage::disk($logoDisk)->url($logoPath)       : null;
        $general['favicon_url'] = $faviconPath ? Storage::disk($faviconDisk)->url($faviconPath) : null;

        $advanced = SystemSetting::orderBy('group')
            ->orderBy('key')
            ->whereNotIn('key', array_merge($generalKeys, ['app_logo_path', 'app_favicon_path']))
            ->get()
            ->map(fn ($s) => [
                'id'        => $s->id,
                'key'       => $s->key,
                'value'     => $s->is_secret
                    ? (strlen($s->attributes['value'] ?? '') > 0 ? '••••••••' : '')
                    : ($s->attributes['value'] ?? ''),
                'is_secret' => $s->is_secret,
                'group'     => $s->group,
            ]);

        $byGroup = $advanced->groupBy('group')->map->values();

        $firebase = [
            'enabled'    => SystemSetting::get('firebase_enabled', 'false') === 'true',
            'apiKey'     => SystemSetting::get('firebase_api_key', ''),
            'authDomain' => SystemSetting::get('firebase_auth_domain', ''),
            'projectId'  => SystemSetting::get('firebase_project_id', ''),
            'appId'      => SystemSetting::get('firebase_app_id', ''),
        ];

        // Fall back to the build defaults so the pickers open on the colours the UI
        // is actually rendering, rather than on an empty/black swatch.
        $general['primary_color']   = $general['primary_color']   ?: config('saas.branding.primary_color', '#237A57');
        $general['secondary_color'] = $general['secondary_color'] ?: config('saas.branding.secondary_color', '#113B2A');
        $general['font_family']     = $general['font_family']     ?: config('saas.branding.font_family', 'plus-jakarta-sans');

        return Inertia::render('Admin/Settings/Index', [
            'general'         => $general,
            'fonts'           => config('saas.branding.fonts', []),
            'settingsByGroup' => $byGroup,
            'firebase'        => $firebase,
        ]);
    }

    public function updateGeneral(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'app_name'        => ['nullable', 'string', 'max:128'],
            'app_tagline'     => ['nullable', 'string', 'max:255'],
            'support_email'   => ['nullable', 'email', 'max:255'],
            'primary_color'   => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            // Whitelist: the slug reaches a fonts.bunny.net URL and the mapped family
            // name reaches a CSS declaration in app.blade.php.
            'font_family'     => ['nullable', 'string', Rule::in(array_keys(config('saas.branding.fonts', [])))],
        ]);

        foreach ($validated as $key => $value) {
            SystemSetting::set($key, $value, false, 'general');
        }

        return back()->with('success', __('General settings saved.'));
    }

    public function uploadLogo(Request $request): RedirectResponse
    {
        // SVG is accepted only because StorageManager stores the SvgSanitizer
        // output for one rather than the bytes that were uploaded. 'image'
        // rejects SVG in Laravel 12 without allow_svg, and the mimes rule spells
        // the whole list out rather than resting on a framework default.
        $request->validate([
            'logo' => ['required', 'image:allow_svg', 'mimes:png,jpg,jpeg,gif,svg,webp', 'max:2048'],
        ]);

        $file = $request->file('logo');
        if (! $file instanceof UploadedFile) {
            abort(422);
        }

        // Through StorageManager rather than putFileAs on the raw upload: it
        // names the file from the detected mime type instead of the client's
        // own, so GIF bytes uploaded as "logo.html" cannot come back off the
        // storage symlink as text/html on our own origin.
        $stored = app(StorageManager::class)->storeImageUpload($file, 'branding');

        if ($stored === null) {
            throw ValidationException::withMessages([
                'logo' => __('This SVG could not be used. Export it again as a plain SVG, without scripts and without internal CSS, or upload a PNG instead.'),
            ]);
        }

        // Only now is the previous file safe to drop: deleting it first would
        // leave app_logo_path naming nothing if the write above had failed.
        $this->deleteFile('app_logo_path', 'app_logo_disk');

        SystemSetting::set('app_logo_path', $stored['path'], false, 'general');
        SystemSetting::set('app_logo_disk', $stored['disk'], false, 'general');

        return back()->with('success', __('Logo uploaded.'));
    }

    public function deleteLogo(): RedirectResponse
    {
        $this->deleteFile('app_logo_path', 'app_logo_disk');
        SystemSetting::whereIn('key', ['app_logo_path', 'app_logo_disk'])->delete();

        return back()->with('success', __('Logo removed.'));
    }

    public function uploadFavicon(Request $request): RedirectResponse
    {
        // 'file' and not 'image' because .ico is a favicon format the image rule
        // does not know; the mimes list tests the detected type either way.
        $request->validate([
            'favicon' => ['required', 'file', 'mimes:png,jpg,jpeg,gif,ico,svg,webp', 'max:512'],
        ]);

        $file = $request->file('favicon');
        if (! $file instanceof UploadedFile) {
            abort(422);
        }

        // Same route as the logo, and for the favicon it is the only thing
        // standing between an uploaded <svg><script> and a stored XSS: the
        // browser parses a favicon URL navigated to directly as a document, and
        // a file served off the storage symlink never reaches SecureHeaders.
        $stored = app(StorageManager::class)->storeImageUpload($file, 'branding');

        if ($stored === null) {
            throw ValidationException::withMessages([
                'favicon' => __('This SVG could not be used. Export it again as a plain SVG, without scripts and without internal CSS, or upload a PNG instead.'),
            ]);
        }

        $this->deleteFile('app_favicon_path', 'app_favicon_disk');

        SystemSetting::set('app_favicon_path', $stored['path'], false, 'general');
        SystemSetting::set('app_favicon_disk', $stored['disk'], false, 'general');

        return back()->with('success', __('Favicon uploaded.'));
    }

    public function deleteFavicon(): RedirectResponse
    {
        $this->deleteFile('app_favicon_path', 'app_favicon_disk');
        SystemSetting::whereIn('key', ['app_favicon_path', 'app_favicon_disk'])->delete();

        return back()->with('success', __('Favicon removed.'));
    }

    public function updateFirebase(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'firebase_enabled'     => ['required', 'in:true,false'],
            'firebase_api_key'     => ['nullable', 'string', 'max:255'],
            'firebase_auth_domain' => ['nullable', 'string', 'max:255'],
            'firebase_project_id'  => ['nullable', 'string', 'max:128'],
            'firebase_app_id'      => ['nullable', 'string', 'max:255'],
        ]);

        foreach ($validated as $key => $value) {
            SystemSetting::set($key, $value ?? '', false, 'firebase');
        }

        return back()->with('success', __('Firebase settings saved.'));
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'settings'             => ['required', 'array'],
            'settings.*.key'       => ['required', 'string', 'max:128'],
            'settings.*.value'     => ['nullable', 'string'],
            'settings.*.is_secret' => ['boolean'],
            'settings.*.group'     => ['nullable', 'string', 'max:64'],
        ]);

        foreach ($validated['settings'] as $s) {
            $model            = SystemSetting::firstOrNew(['key' => $s['key']]);
            $model->is_secret = $s['is_secret'] ?? false;
            $model->group     = $s['group'] ?? null;
            $value            = $s['value'] ?? null;
            if ($value !== null && $value !== '' && ! ($model->is_secret && preg_match('/^•+$/', (string) $value))) {
                $model->value = $value;
            }
            $model->save();
        }

        return back()->with('success', __('Settings saved.'));
    }

    private function deleteFile(string $pathKey, string $diskKey): void
    {
        $existing = SystemSetting::get($pathKey);
        $disk     = SystemSetting::get($diskKey, 'public');
        if ($existing) {
            app(StorageManager::class)->ensureDiskReady($disk);
            Storage::disk($disk)->delete($existing);
        }
    }
}
