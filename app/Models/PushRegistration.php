<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushRegistration extends Model
{
    protected $fillable = [
        'user_id',
        'device_id',
        'token_hash',
        'token_ciphertext',
        'platform',
        'is_active',
        'last_seen_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
        'token_ciphertext',
    ];

    protected function casts(): array
    {
        return [
            'token_ciphertext' => 'encrypted',
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
