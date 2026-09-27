<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
class Unit extends Model {
    public $timestamps = false;
    protected $fillable = ['teacher_subject_id','title','description','price','order_no','status'];
    protected function casts(): array { return ['price'=>'decimal:2']; }
    public function teacherSubject(): BelongsTo { return $this->belongsTo(TeacherSubject::class); }
    public function lessons(): HasMany { return $this->hasMany(Lesson::class); }
    public function enrollments(): MorphMany { return $this->morphMany(Enrollment::class, 'enrollable'); }
}
