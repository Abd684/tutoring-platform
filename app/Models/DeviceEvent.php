<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class DeviceEvent extends Model {
    public $timestamps = false;
    protected $fillable = ['student_device_id','event_type','metadata_json','created_at'];
    protected function casts(): array { return ['metadata_json'=>'array','created_at'=>'datetime']; }
    public function studentDevice(): BelongsTo { return $this->belongsTo(StudentDevice::class); }
}
