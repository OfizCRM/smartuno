<?php

namespace App\Modules\Email\Services;

use App\Modules\Shared\Models\ChannelAccount;

/**
 * The shape of a mailbox, and the only place that knows how it is stored.
 *
 * Everything lives in channel_accounts.credentials, which is cast
 * `encrypted:array` and listed in the model's $hidden — so the passwords are
 * encrypted at rest and cannot be serialised into an Inertia prop by accident.
 * That is the better of the two encryption patterns in this codebase;
 * WorkspaceSmtpConfig's accessor swallows a decryption failure and returns
 * null, which turns a key rotation into silently blank credentials.
 *
 * A workspace has at most one mailbox for now. The table supports more, and the
 * inbox already groups conversations per channel account, so adding a second is
 * a UI change rather than a model change.
 */
class MailboxSettings
{
    public const PROVIDER = 'imap';

    /** How often the poller may run, in minutes. */
    public const POLL_CHOICES = [1, 5, 10, 30];

    public static function forWorkspace(int $workspaceId): ?ChannelAccount
    {
        return ChannelAccount::where('workspace_id', $workspaceId)
            ->where('channel', 'email')
            ->orderBy('id')
            ->first();
    }

    /**
     * What the settings page may show. Never the passwords — the page reports
     * whether one is stored, and re-saving without typing one keeps the old.
     *
     * @return array<string, mixed>|null
     */
    public static function toForm(?ChannelAccount $account): ?array
    {
        if (! $account) {
            return null;
        }

        $creds = $account->getAttribute('credentials') ?? [];
        $meta = $account->meta_json ?? [];

        return [
            'email' => $creds['email'] ?? $account->display_name,
            'from_name' => $creds['from_name'] ?? '',
            'imap' => self::server($creds['imap'] ?? []),
            'smtp' => self::server($creds['smtp'] ?? []),
            'poll_minutes' => (int) ($meta['poll_minutes'] ?? 10),
            'initial_days' => (int) ($meta['initial_days'] ?? 2),
            'status' => $account->status,
            'last_error' => $meta['last_error'] ?? null,
            'last_polled_at' => $meta['last_polled_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $server
     * @return array<string, mixed>
     */
    private static function server(array $server): array
    {
        return [
            'host' => $server['host'] ?? '',
            'port' => $server['port'] ?? null,
            'encryption' => $server['encryption'] ?? 'ssl',
            'username' => $server['username'] ?? '',
            'has_password' => ($server['password'] ?? '') !== '',
        ];
    }

    /**
     * Best guess at the servers for an address, the way a mail client does it.
     * Only ever a prefill — the tenant can overwrite every field.
     *
     * @return array{imap: array<string, mixed>, smtp: array<string, mixed>}
     */
    public static function guessFor(string $email): array
    {
        $domain = strtolower(trim(substr(strrchr($email, '@') ?: '', 1)));

        $known = [
            'gmail.com' => ['imap.gmail.com', 'smtp.gmail.com'],
            'googlemail.com' => ['imap.gmail.com', 'smtp.gmail.com'],
            'outlook.com' => ['outlook.office365.com', 'smtp.office365.com'],
            'hotmail.com' => ['outlook.office365.com', 'smtp.office365.com'],
            'yahoo.com' => ['imap.mail.yahoo.com', 'smtp.mail.yahoo.com'],
        ];

        // Romanian hosting (cPanel/Plesk) almost always answers on mail.<domain>
        // for both, which is the common case for an @firma.ro address.
        [$imapHost, $smtpHost] = $known[$domain] ?? ["mail.{$domain}", "mail.{$domain}"];

        return [
            'imap' => ['host' => $imapHost, 'port' => 993, 'encryption' => 'ssl', 'username' => $email],
            'smtp' => ['host' => $smtpHost, 'port' => 465, 'encryption' => 'ssl', 'username' => $email],
        ];
    }
}
