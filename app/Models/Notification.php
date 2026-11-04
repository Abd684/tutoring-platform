<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'type', 'title', 'body', 'data', 'read_at', 'related_payment_id'];

    protected static function booted(): void
    {
        static::creating(function (Notification $notification) {
            // Keep application timestamps in its configured timezone even when
            // MySQL's session timezone differs from PHP/Laravel's timezone.
            $notification->created_at ??= now();
        });
    }

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime', 'read_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
