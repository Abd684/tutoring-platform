<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Teacher extends Model {
    public $timestamps = false;
    protected $fillable = ['user_id','bio','specialization','status'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function teacherSubjects(): HasMany { return $this->hasMany(TeacherSubject::class); }
    public function enrollments(): HasMany { return $this->hasMany(Enrollment::class); }
    public function availabilities(): HasMany { return $this->hasMany(TeacherAvailability::class); }
    public function sessions(): HasMany { return $this->hasMany(Session::class); }
    public function subscriptions(): HasMany { return $this->hasMany(TeacherSubscription::class); }
    public function escrowTransactions(): HasMany { return $this->hasMany(EscrowTransaction::class); }
}
