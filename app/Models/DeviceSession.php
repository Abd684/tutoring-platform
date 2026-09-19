<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class DeviceSession extends Model {
    public $timestamps = false;
    protected $fillable = ['student_device_id','refresh_token_hash','status','last_activity_at','expires_at','revoked_at','revoke_reason'];
    protected function casts(): array { return ['last_activity_at'=>'datetime','expires_at'=>'datetime','revoked_at'=>'datetime']; }
    public function studentDevice(): BelongsTo { return $this->belongsTo(StudentDevice::class); }
}
