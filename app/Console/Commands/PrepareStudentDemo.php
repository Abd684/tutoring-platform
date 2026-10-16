<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Models\ContentAsset;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Services\VideoMetadata;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class PrepareStudentDemo extends Command
{
    protected $signature = 'student:prepare-demo';

    protected $description = 'Prepare local student trial accounts, playable sample media and a bookable session';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Demo fixtures are disabled in production.');

            return self::FAILURE;
        }
        $this->call('db:seed', ['--class' => 'MobileMediaTestSeeder']);
        $course = TeacherSubject::whereHas('teacher.user', fn ($q) => $q->where('email', 'teacher.mobile@example.com'))
            ->whereHas('subject', fn ($q) => $q->where('name', 'رياضيات - تجربة الجوال'))->firstOrFail();
        $contents = Content::whereHas('lesson.unit', fn ($q) => $q->where('teacher_subject_id', $course->id));
        $video = (clone $contents)->where('type', 'video')->orderBy('id')->firstOrFail();
        $pdf = (clone $contents)->where('type', 'pdf')->orderBy('id')->firstOrFail();
        $disk = Storage::disk('local');
        if (! $video->assets()->where('status', 'active')->where('mime_type', 'like', 'video/%')->exists()) {
            $key = "teachers/{$course->teacher_id}/contents/{$video->id}/demo.mp4";
            $disk->makeDirectory(dirname($key));
            try {
                $process = new Process(['ffmpeg', '-nostdin', '-y', '-f', 'lavfi', '-i', 'testsrc=size=640x360:rate=25',
                    '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=44100', '-t', '3', '-c:v', 'libx264', '-pix_fmt', 'yuv420p',
                    '-c:a', 'aac', '-movflags', '+faststart', $disk->path($key)]);
                $process->setTimeout(60)->mustRun();
            } catch (\Throwable $e) {
                $this->error('ffmpeg is required to generate sample video: '.$e->getMessage());

                return self::FAILURE;
            }
            $asset = ContentAsset::create(['content_id' => $video->id, 'disk' => 'local', 'storage_key' => $key,
                'mime_type' => 'video/mp4', 'file_size' => $disk->size($key), 'status' => 'active', 'encryption_status' => 'pending']);
            app(VideoMetadata::class)->probe($asset);
        }
        foreach ([$pdf, $video] as $content) {
            if ($content->assets()->where('status', 'active')->where('mime_type', 'application/pdf')->exists()) {
                continue;
            }
            $key = "teachers/{$course->teacher_id}/contents/{$content->id}/demo-notes.pdf";
            $disk->put($key, $this->samplePdf());
            ContentAsset::create(['content_id' => $content->id, 'disk' => 'local', 'storage_key' => $key,
                'mime_type' => 'application/pdf', 'file_size' => $disk->size($key), 'status' => 'active', 'encryption_status' => 'pending']);
        }
        TeacherAvailability::firstOrCreate(['teacher_id' => $course->teacher_id,
            'date' => now()->addDay()->toDateString(), 'start_time' => now()->addDay()->startOfHour()->format('H:i:s'),
            'end_time' => now()->addDay()->startOfHour()->addHour()->format('H:i:s'),
            'recurrence_type' => 'none', 'timezone' => config('app.timezone')], ['status' => 'active']);
        $this->info("Demo ready: course {$course->id}, video {$video->id}, PDF {$pdf->id}. Media is synthetic test content.");

        return self::SUCCESS;
    }

    private function samplePdf(): string
    {
        $stream = 'BT /F1 18 Tf 72 740 Td (Liqaa - protected student test attachment) Tj 0 -30 Td (Sample file for integration testing only.) Tj ET';
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', '<< /Length '.strlen($stream).">>\nstream\n".$stream."\nendstream"];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
