<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupJoinRequest extends Model
{
    protected $fillable = ['group_id', 'student_id', 'status', 'pending_guard', 'message', 'review_note', 'reviewed_by', 'reviewed_at'];

    protected $hidden = ['pending_guard'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
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
