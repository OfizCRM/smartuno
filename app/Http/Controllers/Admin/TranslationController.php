<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\I18n\I18nFileService;
use App\Services\I18n\TranslationProviderInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class TranslationController extends Controller
{
    public function __construct(
        private I18nFileService $i18nFiles
    ) {}

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', 'max:10'],
            'flat_key' => ['required', 'string', 'max:255'],
            'value' => ['nullable', 'string'],
        ]);

        $flat = $this->i18nFiles->getFlatDictionary($validated['locale']);
        $flat[$validated['flat_key']] = $validated['value'] ?? '';
        $this->i18nFiles->putFlatDictionary($validated['locale'], $flat);
        $this->i18nFiles->invalidateCache();

        return back()->with('success', __('Translation saved.'));
    }

    public function bulkUpdate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', 'max:10'],
            'translations' => ['required', 'array'],
            'translations.*.flat_key' => ['required', 'string', 'max:255'],
            'translations.*.value' => ['nullable', 'string'],
        ]);

        $flat = $this->i18nFiles->getFlatDictionary($validated['locale']);
        foreach ($validated['translations'] as $row) {
            $flat[$row['flat_key']] = $row['value'] ?? '';
        }
        $this->i18nFiles->putFlatDictionary($validated['locale'], $flat);
        $this->i18nFiles->invalidateCache();

        return back()->with('success', __(':count translations saved.', ['count' => count($validated['translations'])]));
    }

    public function autoTranslateMissing(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', 'exists:locales,code'],
        ]);

        $targetCode = $validated['locale'];
        if ($targetCode === 'en') {
            return back()->with('info', __('English is the source; no auto-translate needed.'));
        }

        $provider = App::bound(TranslationProviderInterface::class)
            ? App::make(TranslationProviderInterface::class)
            : null;

        if (! $provider) {
            return back()->with('error', __('No translation provider configured. Set OPENAI_API_KEY or similar in .env.'));
        }

        $enFlat = $this->i18nFiles->getFlatDictionary('en');
        $targetFlat = $this->i18nFiles->getFlatDictionary($targetCode);
        $updated = 0;

        foreach ($enFlat as $key => $enVal) {
            $current = $targetFlat[$key] ?? '';
            if ($current === '' || $current === $enVal) {
                $translated = $provider->translate($enVal ?? '', 'en', $targetCode);
                if ($translated !== null) {
                    $targetFlat[$key] = $translated;
                    $updated++;
                } else {
                    $targetFlat[$key] = $enVal;
                }
            }
        }

        $this->i18nFiles->putFlatDictionary($targetCode, $targetFlat);
        $this->i18nFiles->invalidateCache();

        return back()->with('success', __('Auto-translated :count missing entries for :locale.', ['count' => $updated, 'locale' => $targetCode]));
    }
}
