<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupStudent extends Model
{
    public $timestamps = false;

    protected $fillable = ['group_id', 'student_id', 'status', 'joined_at', 'left_at'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'left_at' => 'datetime'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
