<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Quiz extends Model {
    public $timestamps = false;
    protected $fillable = ['content_id','duration_minutes','attempts_allowed','status'];
    public function content(): BelongsTo { return $this->belongsTo(Content::class); }
    public function questions(): HasMany { return $this->hasMany(Question::class); }
    public function attempts(): HasMany { return $this->hasMany(QuizAttempt::class); }
}
