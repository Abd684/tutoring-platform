<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceTransferRequest extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'resolved_at' => 'datetime', 'claimed_at' => 'datetime'];
    }

    public function sourceStudentDevice()
    {
        return $this->belongsTo(StudentDevice::class, 'source_student_device_id');
    }
}
