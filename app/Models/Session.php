<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Session extends Model {
    public $timestamps = false;
    protected $fillable = ['teacher_id','availability_id','type','group_id','enrollment_id','starts_at','ends_at','capacity','status'];
    protected function casts(): array { return ['starts_at'=>'datetime','ends_at'=>'datetime','capacity'=>'integer']; }
    public function teacher(): BelongsTo { return $this->belongsTo(Teacher::class); }
    public function availability(): BelongsTo { return $this->belongsTo(TeacherAvailability::class, 'availability_id'); }
    public function group(): BelongsTo { return $this->belongsTo(Group::class); }
    public function enrollment(): BelongsTo { return $this->belongsTo(Enrollment::class); }
    public function attendance(): HasMany { return $this->hasMany(Attendance::class); }
}
