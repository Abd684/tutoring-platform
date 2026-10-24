<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function dashboard(): array
    {
        return array_replace(['platform_name' => 'لقاء', 'support_email' => '', 'timezone' => 'Asia/Damascus', 'locale' => 'ar', 'theme' => 'light', 'density' => 'comfortable'],
            static::where('key', 'dashboard')->value('value') ?? []);
    }
}
