<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
class TeacherSubject extends Model {
    public $timestamps = false;
    protected $fillable = ['teacher_id','subject_id','price','group_enabled','max_group_size','status'];
    protected function casts(): array { return ['price'=>'decimal:2','group_enabled'=>'boolean','max_group_size'=>'integer']; }
    public function teacher(): BelongsTo { return $this->belongsTo(Teacher::class); }
    public function subject(): BelongsTo { return $this->belongsTo(Subject::class); }
    public function units(): HasMany { return $this->hasMany(Unit::class); }
    public function groups(): HasMany { return $this->hasMany(Group::class); }
    public function enrollments(): MorphMany { return $this->morphMany(Enrollment::class, 'enrollable'); }
}
