<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Question extends Model {
    public $timestamps = false;
    protected $fillable = ['quiz_id','type','body','points','rubric_json','order_no'];
    protected function casts(): array { return ['points'=>'decimal:2','rubric_json'=>'array']; }
    public function quiz(): BelongsTo { return $this->belongsTo(Quiz::class); }
    public function answers(): HasMany { return $this->hasMany(Answer::class); }
}
