<?php

namespace App\Modules\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A connected account on one channel: a WhatsApp number, an Instagram or
 * Messenger page, or a mailbox.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $channel
 * @property string|null $provider
 *
 * `credentials` is deliberately NOT declared: it is an encrypted cast, so
 * reading it decrypts and THROWS on corrupt ciphertext. Typing it as a plain
 * array convinces static analysis the read cannot fail and marks the defensive
 * catch in MessengerProfileTestCommand as dead — which it is not. Reach it with
 * getAttribute()/setAttribute() instead.
 * @property string|null $display_name
 * @property string|null $phone_number_id
 * @property string|null $business_account_id
 * @property string $status
 * @property array<string, mixed>|null $meta_json
 */
class ChannelAccount extends Model
{
    protected $fillable = [
        'workspace_id', 'channel', 'provider', 'credentials',
        'display_name', 'phone_number_id', 'business_account_id', 'status', 'meta_json',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'meta_json' => 'array',
        ];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
