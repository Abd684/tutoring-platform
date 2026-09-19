<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Device extends Model {
    public $timestamps = false;
    protected $fillable = ['device_uuid','platform','manufacturer','model','os_version','app_version','public_key','status'];
    public function studentDevices(): HasMany { return $this->hasMany(StudentDevice::class); }
}
