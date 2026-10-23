<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Student;
use App\Models\StudentDevice;

class DeviceBindings
{
    public function activate(Student $student, Device $device): StudentDevice
    {
        return app(DeviceManagementService::class)->activate($student, $device);
    }
}
