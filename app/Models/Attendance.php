<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    protected $table = 'attendance';

    public $timestamps = false;

    protected $fillable = ['session_id', 'student_id', 'status', 'check_in_at', 'check_out_at', 'note'];

    protected function casts(): array
    {
        return ['check_in_at' => 'datetime', 'check_out_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
