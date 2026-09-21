<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends Model
{
    public $timestamps = false;

    protected $fillable = ['name', 'region_id', 'student_id'];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }
}
