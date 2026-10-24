<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentSetting extends Model
{
    public $incrementing = false;

    protected $fillable = ['account_id', 'qr_path'];

    protected $hidden = ['qr_path'];
}
