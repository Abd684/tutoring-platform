<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Group extends Model {
    public $timestamps = false;
    protected $fillable = ['teacher_subject_id','name','capacity','status'];
    public function teacherSubject(): BelongsTo { return $this->belongsTo(TeacherSubject::class); }
    public function groupEnrollments(): HasMany { return $this->hasMany(GroupEnrollment::class); }
    public function sessions(): HasMany { return $this->hasMany(Session::class); }
}
