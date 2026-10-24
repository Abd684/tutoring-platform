<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentCheckout extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['qr_path'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'expires_at' => 'datetime', 'created_at' => 'datetime', 'booking_starts_at' => 'datetime', 'booking_ends_at' => 'datetime'];
    }
}
