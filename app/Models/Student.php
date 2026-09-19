<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Student extends Model {
    public $timestamps = false;
    protected $fillable = ['user_id','grade','school','status'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function enrollments(): HasMany { return $this->hasMany(Enrollment::class); }
    public function studentDevices(): HasMany { return $this->hasMany(StudentDevice::class); }
    public function quizAttempts(): HasMany { return $this->hasMany(QuizAttempt::class); }
}
