<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Region extends Model
{
    public $timestamps = false;

    protected $fillable = ['name', 'governortate_id'];

    public function governortate(): BelongsTo
    {
        return $this->belongsTo(Governortate::class);
    }

    public function schools(): HasMany
    {
        return $this->hasMany(School::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
