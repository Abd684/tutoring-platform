<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentCompletion extends Model
{
    public $timestamps = false;

    protected $fillable = ['student_id', 'content_id', 'completed_at'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
