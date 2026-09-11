<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class SmtpConfiguration extends Model
{
    /**
     * Send over SMTP — the default, and correct wherever outbound 587 works.
     */
    public const TRANSPORT_SMTP = 'smtp';

    /**
     * Send over Brevo's HTTPS API instead.
     *
     * For hosts that block outbound SMTP. The failure it avoids is not a clean
     * refusal — the connection hangs until it times out, which reads like a
     * broken credential rather than a blocked port.
     *
     * An API row uses `username` for nothing and `password` for the API key,
     * which is a v3 key from Brevo's "API keys" tab — NOT the SMTP key, which
     * is a different credential for the other door.
     */
    public const TRANSPORT_BREVO_API = 'brevo_api';

    /** @var list<string> */
    public const TRANSPORTS = [self::TRANSPORT_SMTP, self::TRANSPORT_BREVO_API];

    protected $fillable = [
        'transport',
        'host',
        'port',
        'username',
        'password',
        'encryption',
        'from_email',
        'from_name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected $hidden = ['password'];

    public function setPasswordAttribute(?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $this->attributes['password'] = Crypt::encryptString($value);
    }

    public function getPasswordAttribute(string $value): ?string
    {
        if (empty($value)) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function getDecryptedPassword(): ?string
    {
        return $this->password;
    }

    /** Whether this configuration sends over an HTTP API rather than SMTP. */
    public function usesApi(): bool
    {
        return $this->transport === self::TRANSPORT_BREVO_API;
    }

    public static function getActive(): ?self
    {
        return static::where('is_active', true)->first();
    }

    /**
     * Whether an active SMTP configuration exists (i.e. the app can send real mail).
     */
    public static function isConfigured(): bool
    {
        return static::getActive() !== null;
    }

    /**
     * One line describing where this configuration sends, for the admin list.
     *
     * An API configuration has no host and no port — those columns describe an
     * SMTP connection — so "host:port" would render as a bare colon and look
     * like a broken row rather than a different kind of row.
     */
    public function getSummaryAttribute(): string
    {
        if ($this->usesApi()) {
            return 'Brevo API (HTTPS)';
        }

        return "{$this->host}:{$this->port}";
    }
}
