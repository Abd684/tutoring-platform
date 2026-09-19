<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
class Content extends Model {
    public $timestamps = false;
    protected $fillable = ['lesson_id','type','title','description','order_no','status','published_at'];
    protected function casts(): array { return ['published_at'=>'datetime']; }
    public function lesson(): BelongsTo { return $this->belongsTo(Lesson::class); }
    public function assets(): HasMany { return $this->hasMany(ContentAsset::class); }
    public function quizzes(): HasMany { return $this->hasMany(Quiz::class); }
    public function enrollments(): MorphMany { return $this->morphMany(Enrollment::class, 'enrollable'); }
}
