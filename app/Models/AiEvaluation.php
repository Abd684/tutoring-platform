<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class AiEvaluation extends Model {
    public $timestamps = false;
    protected $fillable = ['answer_id','model','suggested_score','confidence','explanation','teacher_score','status'];
    protected function casts(): array { return ['suggested_score'=>'decimal:2','confidence'=>'decimal:4','teacher_score'=>'decimal:2']; }
    public function answer(): BelongsTo { return $this->belongsTo(Answer::class); }
}
