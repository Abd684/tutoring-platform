<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
class Answer extends Model {
    public $timestamps = false;
    protected $fillable = ['attempt_id','question_id','answer_text','score','status'];
    protected function casts(): array { return ['score'=>'decimal:2']; }
    public function attempt(): BelongsTo { return $this->belongsTo(QuizAttempt::class, 'attempt_id'); }
    public function question(): BelongsTo { return $this->belongsTo(Question::class); }
    public function aiEvaluation(): HasOne { return $this->hasOne(AiEvaluation::class); }
}
