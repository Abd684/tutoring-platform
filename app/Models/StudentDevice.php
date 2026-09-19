<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class StudentDevice extends Model {
    public $timestamps = false;
    protected $fillable = ['student_id','device_id','status','is_active','activated_at','last_seen_at','revoked_at','revoke_reason'];
    protected function casts(): array { return ['is_active'=>'boolean','activated_at'=>'datetime','last_seen_at'=>'datetime','revoked_at'=>'datetime']; }
    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function device(): BelongsTo { return $this->belongsTo(Device::class); }
    public function deviceSessions(): HasMany { return $this->hasMany(DeviceSession::class); }
    public function deviceEvents(): HasMany { return $this->hasMany(DeviceEvent::class); }
}
