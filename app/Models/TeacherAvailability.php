<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class TeacherAvailability extends Model {
    public $timestamps = false;
    protected $fillable = ['teacher_id','weekday','date','start_time','end_time','recurrence_type','timezone','status'];
    protected function casts(): array { return ['date'=>'date','weekday'=>'integer']; }
    public function teacher(): BelongsTo { return $this->belongsTo(Teacher::class); }
    public function sessions(): HasMany { return $this->hasMany(Session::class, 'availability_id'); }
}
