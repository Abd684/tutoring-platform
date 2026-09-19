<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
class Enrollment extends Model {
    public $timestamps = false;
    protected $fillable = ['student_id','teacher_id','enrollable_type','enrollable_id','price','status','starts_at','expires_at'];
    protected function casts(): array { return ['price'=>'decimal:2','starts_at'=>'datetime','expires_at'=>'datetime']; }
    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function teacher(): BelongsTo { return $this->belongsTo(Teacher::class); }
    public function enrollable(): MorphTo { return $this->morphTo(); }
    public function groupEnrollments(): HasMany { return $this->hasMany(GroupEnrollment::class); }
    public function sessions(): HasMany { return $this->hasMany(Session::class); }
    public function attendance(): HasMany { return $this->hasMany(Attendance::class); }
    public function escrowTransactions(): HasMany { return $this->hasMany(EscrowTransaction::class); }
}
