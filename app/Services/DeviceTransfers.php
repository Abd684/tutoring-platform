<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceEvent;
use App\Models\DeviceTransferRequest;
use App\Models\Notification;
use App\Models\Student;
use App\Models\StudentDevice;
use Illuminate\Support\Facades\DB;

class DeviceTransfers
{
    public function resolve(int $id, ?string $decision = null): DeviceTransferRequest
    {
        $studentId = DeviceTransferRequest::findOrFail($id)->student_id;

        return DB::transaction(function () use ($id, $studentId, $decision) {
            $student = Student::whereKey($studentId)->lockForUpdate()->firstOrFail();
            $transfer = DeviceTransferRequest::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($transfer->status !== 'pending') {
                return $transfer;
            }
            $expired = $transfer->expires_at->lte(now());
            if (! $decision && ! $expired) {
                return $transfer;
            }
            $source = StudentDevice::whereKey($transfer->source_student_device_id)->lockForUpdate()->firstOrFail();
            $target = Device::whereKey($transfer->target_device_id)->lockForUpdate()->firstOrFail();
            if ($student->status !== 'active' || $student->user?->status !== 'active' || $target->status === 'blocked'
                || $source->status !== 'active' || ! $source->is_active) {
                $transfer->update(['status' => 'cancelled', 'resolved_at' => now()]);

                return $transfer;
            }
            // The deadline wins over a late rejection.
            if ($decision === 'reject' && ! $expired) {
                $transfer->update(['status' => 'rejected', 'resolved_at' => now()]);

                return $transfer;
            }
            $new = app(DeviceManagementService::class)->activate($student, $target);
            foreach ([$source->id => 'device_transfer_out', $new->id => 'device_transfer_in'] as $deviceId => $event) {
                DeviceEvent::create(['student_device_id' => $deviceId, 'event_type' => $event,
                    'metadata_json' => ['transfer_request_id' => $transfer->id, 'automatic' => $expired], 'created_at' => now()]);
            }
            $transfer->update(['status' => $expired ? 'auto_approved' : 'approved', 'resolved_at' => now()]);
            Notification::create(['user_id' => $student->user_id, 'type' => 'device_transfer_completed',
                'title' => 'Device transfer completed', 'body' => json_encode(['transfer_request_id' => $transfer->id])]);

            return $transfer;
        });
    }
}
