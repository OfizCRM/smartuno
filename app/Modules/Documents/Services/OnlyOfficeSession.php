<?php

namespace App\Modules\Documents\Services;

use App\Models\User;
use App\Modules\Documents\Models\Document;
use Illuminate\Support\Facades\URL;

/**
 * Everything the ONLYOFFICE editor needs to open one document, signed.
 *
 * Three things about this integration are easy to get wrong and are all handled
 * here rather than scattered through controllers:
 *
 *  * The Document Server has no session. It fetches the file over plain HTTP,
 *    so the URL it is given is signed and short-lived, and the workspace is
 *    checked at the moment it is minted rather than when it is used.
 *  * The signature is RELATIVE. The container reaches the application on a
 *    different host name than the browser does — host.docker.internal rather
 *    than localhost — and an absolute signature covers the host, so it would
 *    fail every time.
 *  * The key must change whenever the file does. The Document Server caches by
 *    key, and reusing one serves yesterday's copy while the editor looks fine.
 */
class OnlyOfficeSession
{
    /** How long the Document Server has to collect the file. */
    private const DOWNLOAD_TTL_MINUTES = 30;

    /** Which editor opens which extension. */
    private const TYPES = [
        'word' => ['doc', 'docx', 'odt', 'rtf', 'txt'],
        'cell' => ['xls', 'xlsx', 'ods', 'csv'],
        'slide' => ['ppt', 'pptx', 'odp'],
        'pdf' => ['pdf'],
    ];

    public function available(): bool
    {
        return $this->url() !== null && $this->secret() !== '';
    }

    /** Whether this file is something the editor can open at all. */
    public function opens(string $extension): bool
    {
        return $this->documentType($extension) !== null;
    }

    /** True when the file can be changed, rather than only read. */
    public function edits(string $extension): bool
    {
        return in_array($this->documentType($extension), ['word', 'cell', 'slide'], true);
    }

    public function url(): ?string
    {
        $url = trim((string) config('services.onlyoffice.url'));

        return $url !== '' ? rtrim($url, '/') : null;
    }

    /**
     * The whole editor configuration, with its own signature inside.
     *
     * @return array<string, mixed>
     */
    public function config(Document $document, User $user, bool $editable): array
    {
        $extension = strtolower($document->extension);

        $config = [
            'document' => [
                'fileType' => $extension,
                'key' => $this->key($document),
                'title' => $document->name,
                'url' => $this->downloadUrl($document),
                'permissions' => [
                    'edit' => $editable,
                    'download' => true,
                    'print' => true,
                    // Nothing here is a collaborative feature we have tested, so
                    // nothing here claims to be one.
                    'comment' => false,
                    'chat' => false,
                ],
            ],
            'documentType' => $this->documentType($extension),
            'editorConfig' => [
                'mode' => $editable ? 'edit' : 'view',
                'callbackUrl' => $editable ? $this->callbackUrl($document) : null,
                'lang' => app()->getLocale(),
                'user' => [
                    'id' => (string) $user->id,
                    'name' => $user->name,
                ],
                'customization' => [
                    // The ONLYOFFICE logo stays: the Community licence requires
                    // it, and hiding it would breach the terms we are using it
                    // under. Only the things we are allowed to set are set.
                    'autosave' => true,
                    'forcesave' => true,
                    'compactHeader' => true,
                    'toolbarNoTabs' => false,
                ],
            ],
        ];

        // The signature covers the configuration the browser hands over, so a
        // page cannot ask for a document by editing the object in devtools.
        $config['token'] = $this->sign($config);

        return $config;
    }

    /**
     * A key the Document Server can cache against.
     *
     * The version count is in it because a saved change writes a new version:
     * without that the server would keep serving the copy it already has.
     */
    public function key(Document $document): string
    {
        $version = (int) $document->versions()->count();

        // Letters, digits, dot, dash and underscore only, and at most 128 chars.
        return substr(str_replace('-', '', $document->uuid).'_'.$version, 0, 128);
    }

    /**
     * Where the Document Server reports a save.
     *
     * Longer-lived than the download link: the callback arrives some seconds
     * after the last person closes the document, and a session left open all
     * afternoon must still be able to save.
     */
    public function callbackUrl(Document $document): string
    {
        $relative = URL::temporarySignedRoute(
            'documents.office.callback',
            now()->addHours(12),
            ['document' => $document->uuid],
            absolute: false,
        );

        return rtrim((string) config('services.onlyoffice.app_url'), '/').$relative;
    }

    /**
     * Rewrite a URL the Document Server handed us to one we can actually reach.
     *
     * It builds these from its own idea of where it lives, which inside a
     * container is not where we reach it from. The path is what matters; the
     * host is replaced with the one we are configured to call.
     */
    public function reachable(string $url): string
    {
        $base = $this->url();

        if ($base === null) {
            return $url;
        }

        $parts = parse_url($url);
        $path = ($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');

        return rtrim($base, '/').$path;
    }

    /** Signed, relative, and short-lived — see the note on the class. */
    public function downloadUrl(Document $document): string
    {
        $relative = URL::temporarySignedRoute(
            'documents.office.download',
            now()->addMinutes(self::DOWNLOAD_TTL_MINUTES),
            ['document' => $document->uuid],
            absolute: false,
        );

        return rtrim((string) config('services.onlyoffice.app_url'), '/').$relative;
    }

    /**
     * HS256, from what PHP already has. A whole package for three lines is not
     * warranted.
     *
     * @param  array<string, mixed>  $payload
     */
    public function sign(array $payload): string
    {
        $segments = [
            $this->base64Url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES)),
            $this->base64Url(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];

        $segments[] = $this->base64Url(
            hash_hmac('sha256', implode('.', $segments), $this->secret(), true),
        );

        return implode('.', $segments);
    }

    /**
     * Read a token the Document Server sent us.
     *
     * hash_equals, not ==: a plain comparison leaks how much of a forged
     * signature was right, one byte at a time.
     *
     * @return array<string, mixed>|null null when it is not ours
     */
    public function verify(?string $token): ?array
    {
        if (! $token || substr_count($token, '.') !== 2) {
            return null;
        }

        [$header, $payload, $signature] = explode('.', $token);
        $expected = $this->base64Url(hash_hmac('sha256', $header.'.'.$payload, $this->secret(), true));

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $decoded = json_decode($this->base64UrlDecode($payload), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function documentType(string $extension): ?string
    {
        foreach (self::TYPES as $type => $extensions) {
            if (in_array(strtolower($extension), $extensions, true)) {
                return $type;
            }
        }

        return null;
    }

    private function secret(): string
    {
        return (string) config('services.onlyoffice.secret');
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
