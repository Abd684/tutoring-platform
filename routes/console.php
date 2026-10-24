<?php

use App\Models\ContentAsset;
use App\Models\DeviceTransferRequest;
use App\Models\Enrollment;
use App\Models\PaidBooking;
use App\Models\Student;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Services\DeviceTransfers;
use App\Services\PaidLessonBookings;
use App\Services\VideoMetadata;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('devices:resolve-transfers', function () {
    $count = 0;
    DeviceTransferRequest::where('status', 'pending')->where('expires_at', '<=', now())
        ->select('id')->chunkById(100, function ($requests) use (&$count) {
            foreach ($requests as $request) {
                app(DeviceTransfers::class)->resolve($request->id);
                $count++;
            }
        });
    $this->info("Resolved {$count} device transfer requests.");
});
Schedule::command('devices:resolve-transfers')->everyMinute()->withoutOverlapping();

Artisan::command('payments:expire-holds', function () {
    $count = 0;
    PaidBooking::where('status', 'HELD')->where('hold_expires_at', '<=', now())
        ->select('id', 'payment_id')->chunkById(100, function ($bookings) use (&$count) {
            foreach ($bookings as $booking) {
                if (app(PaidLessonBookings::class)->expire($booking)) {
                    $count++;
                }
            }
        });
    $this->info("Expired {$count} paid booking holds. Payment decisions are unchanged.");
});
Schedule::command('payments:expire-holds')->everyMinute()->withoutOverlapping();

Artisan::command('media:probe-durations', function () {
    $count = 0;
    ContentAsset::where('status', 'active')->where('mime_type', 'like', 'video/%')
        ->chunkById(100, function ($assets) use (&$count) {
            foreach ($assets as $asset) {
                if (app(VideoMetadata::class)->probe($asset) !== null) {
                    $count++;
                }
            }
        });
    $this->info("Updated {$count} video durations. Missing durations require ffprobe and local media files.");
});

Artisan::command('student:grant-trial {student_user : User ID or email} {teacher_subject_id} {--days=14}', function () {
    $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
    if (! $days || $days < 1 || $days > 365) {
        $this->error('Days must be between 1 and 365.');

        return 1;
    }
    $identifier = $this->argument('student_user');
    $user = User::where(ctype_digit($identifier) ? 'id' : 'email', $identifier)->where('role', 'student')->where('status', 'active')->first();
    $course = TeacherSubject::whereKey($this->argument('teacher_subject_id'))->where('status', 'active')
        ->whereHas('teacher', fn ($q) => $q->where('status', 'active')->whereHas('user', fn ($q) => $q->where('status', 'active')))->first();
    if (! $user?->student || $user->student->status !== 'active' || ! $course) {
        $this->error('Active student and course are required.');

        return 1;
    }
    $enrollment = DB::transaction(function () use ($user, $course, $days) {
        Student::whereKey($user->student->id)->lockForUpdate()->firstOrFail();
        $existing = Enrollment::where('student_id', $user->student->id)
            ->where('enrollable_type', 'teacher_subject')->where('enrollable_id', $course->id)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first();
        if ($existing) {
            return $existing;
        }

        return Enrollment::create(['student_id' => $user->student->id, 'teacher_id' => $course->teacher_id,
            'enrollable_type' => (new TeacherSubject)->getMorphClass(), 'enrollable_id' => $course->id,
            'price' => 0, 'status' => 'active', 'starts_at' => now(), 'expires_at' => now()->addDays($days)]);
    });
    $this->info('Enrollment '.$enrollment->id.' is active; expires: '.($enrollment->expires_at?->toIso8601String() ?? 'unlimited'));
});

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
