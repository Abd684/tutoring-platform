<?php

namespace Tests\Feature;

use App\Console\Commands\ProcessPendingPdfs;
use App\Models\{Content, ContentAsset, Lesson, Subject, Teacher, TeacherSubject, Unit, User};
use App\Services\PdfWorkerLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class PdfWorkerTest extends TestCase
{
    use RefreshDatabase;

    private function asset(array $extra = []): ContentAsset
    {
        static $number = 0;
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher'])->id, 'status' => 'active']);
        $subject = Subject::create(['name' => 'Worker test '.++$number, 'status' => 'active']);
        $course = TeacherSubject::create(['teacher_id' => $teacher->id, 'subject_id' => $subject->id, 'status' => 'active']);
        $unit = Unit::create(['teacher_subject_id' => $course->id, 'title' => 'Unit', 'status' => 'published']);
        $lesson = Lesson::create(['unit_id' => $unit->id, 'title' => 'Lesson', 'status' => 'published']);
        $content = Content::create(['lesson_id' => $lesson->id, 'type' => 'pdf', 'title' => 'Test', 'status' => 'published']);
        return ContentAsset::create(array_merge(['content_id' => $content->id, 'disk' => 'local',
            'storage_key' => 'test.pdf', 'mime_type' => 'application/pdf', 'status' => 'active',
            'processing_status' => 'pending'], $extra));
    }

    private function worker(?\Closure $behavior = null): object
    {
        $worker = new class($behavior) extends ProcessPendingPdfs {
            public array $seen = [];
            public function __construct(private ?\Closure $behavior) { parent::__construct(); }
            protected function render(ContentAsset $asset, int $timeout): int {
                $this->seen[] = $asset->id;
                if ($this->behavior) return ($this->behavior)($asset, $timeout);
                $asset->update(['processing_status' => 'ready', 'total_pages' => 4]);
                return 0;
            }
        };
        $worker->setLaravel(app());
        return $worker;
    }

    private function runWorker(object $worker, int $limit = 20): void
    {
        $this->assertSame(0, $worker->run(new ArrayInput(['--limit' => $limit]), new BufferedOutput()));
    }

    public function test_ten_teacher_uploads_are_processed_in_fifo_order_and_not_repeated(): void
    {
        $ids = [];
        for ($i = 0; $i < 10; $i++) $ids[] = $this->asset()->id;
        $worker = $this->worker();
        $this->runWorker($worker);
        $this->assertSame($ids, $worker->seen);
        $this->assertSame(10, ContentAsset::where('processing_status', 'ready')->count());
        $this->assertSame(10, ContentAsset::sum('processing_attempts'));
        $this->runWorker($worker);
        $this->assertSame($ids, $worker->seen);
    }

    public function test_worker_lock_prevents_a_second_worker_from_claiming_any_file(): void
    {
        $asset = $this->asset();
        $locks = app(PdfWorkerLock::class);
        $lock = $locks->acquire('worker');
        try { $worker = $this->worker(); $this->runWorker($worker); $this->assertSame([], $worker->seen); }
        finally { $locks->release($lock); }
        $this->assertSame('pending', $asset->fresh()->processing_status);
    }

    public function test_failed_file_backs_off_and_does_not_block_the_next_teacher(): void
    {
        $bad = $this->asset(); $good = $this->asset();
        $worker = $this->worker(function ($a) use ($bad) {
            if ($a->id === $bad->id) return 1;
            $a->update(['processing_status' => 'ready']); return 0;
        });
        $this->runWorker($worker);
        $this->assertSame([$bad->id, $good->id], $worker->seen);
        $this->assertSame('failed', $bad->fresh()->processing_status);
        $this->assertTrue($bad->fresh()->next_processing_at->isFuture());
        $this->runWorker($worker);
        $this->assertCount(2, $worker->seen);
        $this->travel(61)->seconds(); $this->runWorker($worker);
        $this->travel(121)->seconds(); $this->runWorker($worker);
        $this->assertSame(3, $bad->fresh()->processing_attempts);
        $this->travel(1000)->seconds(); $this->runWorker($worker);
        $this->assertCount(4, $worker->seen); // bad three times, good once
    }

    public function test_stale_processing_is_recovered_but_live_renderer_is_not_interrupted(): void
    {
        $asset = $this->asset(['processing_status' => 'processing', 'processing_attempts' => 1,
            'processing_started_at' => now()->subHour()]);
        $locks = app(PdfWorkerLock::class); $lock = $locks->acquire('renderer');
        try { $worker = $this->worker(); $this->runWorker($worker); $this->assertSame([], $worker->seen); }
        finally { $locks->release($lock); }
        $this->runWorker($worker);
        $this->assertSame([$asset->id], $worker->seen);
        $this->assertSame('ready', $asset->fresh()->processing_status);
    }

    public function test_timeout_marks_failure_and_next_file_still_runs(): void
    {
        $bad = $this->asset(); $good = $this->asset();
        $worker = $this->worker(function ($a) use ($bad) {
            if ($a->id === $bad->id) {
                $p = new \Symfony\Component\Process\Process([PHP_BINARY, '-v']);
                throw new \Symfony\Component\Process\Exception\ProcessTimedOutException($p, 1);
            }
            $a->update(['processing_status' => 'ready']); return 0;
        });
        $this->runWorker($worker);
        $this->assertSame('PDF_PROCESSING_TIMEOUT', $bad->fresh()->processing_error_code);
        $this->assertSame('ready', $good->fresh()->processing_status);
    }

    public function test_archived_and_exhausted_documents_are_excluded(): void
    {
        $this->asset(['status' => 'archived']);
        $this->asset(['processing_status' => 'failed', 'processing_attempts' => 3]);
        $worker = $this->worker(); $this->runWorker($worker);
        $this->assertSame([], $worker->seen);
    }

    public function test_batch_limit_leaves_remaining_files_for_the_next_tick(): void
    {
        $first = $this->asset(); $second = $this->asset();
        $worker = $this->worker(); $this->runWorker($worker, 1);
        $this->assertSame([$first->id], $worker->seen);
        $this->assertSame('pending', $second->fresh()->processing_status);
        $this->runWorker($worker, 1);
        $this->assertSame([$first->id, $second->id], $worker->seen);
    }

    public function test_busy_child_is_requeued_without_spending_a_retry(): void
    {
        $asset = $this->asset();
        $worker = $this->worker(fn () => 75); $this->runWorker($worker);
        $this->assertSame('pending', $asset->fresh()->processing_status);
        $this->assertSame(0, $asset->fresh()->processing_attempts);
    }

    public function test_processor_rejects_oversize_input_before_rendering(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put('test.pdf', str_repeat('x', 100));
        config(['pdf.max_source_bytes' => 10]);
        $asset = $this->asset();
        $renderer = \Mockery::mock(\App\Contracts\PdfRenderer::class);
        $renderer->shouldNotReceive('render');
        $processor = new \App\Services\SecurePdfProcessor($renderer, app(\App\Services\PdfEncryption::class));
        $processor->process($asset);
        $this->assertSame('failed', $asset->fresh()->processing_status);
        $this->assertSame('PDF_SOURCE_SIZE_LIMIT', $asset->fresh()->processing_error_code);
    }
}
