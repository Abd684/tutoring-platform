<?php

// Isolated local integration test. Never reads a production .env or database.
$root = dirname(__DIR__, 2);
$sandbox = $root.'/storage/framework/testing/pdf-worker-'.bin2hex(random_bytes(6));
foreach (['app/private', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $dir) {
    mkdir($sandbox.'/'.$dir, 0777, true);
}
$db = $sandbox.'/database.sqlite';
touch($db);
$vars = ['APP_ENV'=>'testing', 'APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),
    'DB_CONNECTION'=>'sqlite', 'DB_DATABASE'=>$db, 'DB_URL'=>'', 'CACHE_STORE'=>'array',
    'SESSION_DRIVER'=>'array', 'MEDIA_DISK'=>'local', 'FILESYSTEM_DISK'=>'local',
    'LARAVEL_STORAGE_PATH'=>$sandbox, 'APP_CONFIG_CACHE'=>$sandbox.'/config.php', 'LOG_CHANNEL'=>'single', 'PDF_RENDERER'=>'poppler', 'PDF_MASTER_KEY'=>base64_encode(random_bytes(32)),
    'PDF_WORKER_BUDGET_SECONDS'=>'600', 'PDF_RETRY_DELAY_SECONDS'=>'600', 'FIREBASE_PUSH_ENABLED'=>'false',
    'PDFTOPPM_BINARY'=>getenv('PDFTOPPM_BINARY') ?: 'pdftoppm',
    'PDFINFO_BINARY'=>getenv('PDFINFO_BINARY') ?: 'pdfinfo'];
foreach ($vars as $k=>$v) { putenv($k.'='.$v); $_ENV[$k]=$v; $_SERVER[$k]=$v; }
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Content, ContentAsset, Enrollment, Lesson, Student, Subject, Teacher, TeacherSubject, Unit, User};
use Illuminate\Support\Facades\{Artisan, Storage};
use Symfony\Component\Process\Process;

$checks=[];
$assert=function($condition,$name)use(&$checks){if(!$condition)throw new RuntimeException($name);$checks[]=$name;};
Artisan::call('migrate', ['--force'=>true]);
$fixture = $root.'/tests/Fixtures/pdf-viewer-demo.pdf';
$ids=[];
for($i=0;$i<11;$i++){
    $teacher=Teacher::create(['user_id'=>User::factory()->create(['role'=>'teacher','status'=>'active'])->id,'status'=>'active']);
    $subject=Subject::create(['name'=>'Local worker test '.$i,'status'=>'active']);
    $course=TeacherSubject::create(['teacher_id'=>$teacher->id,'subject_id'=>$subject->id,'status'=>'active','price'=>0]);
    $unit=Unit::create(['teacher_subject_id'=>$course->id,'title'=>'Test','status'=>'published','price'=>0]);
    $lesson=Lesson::create(['unit_id'=>$unit->id,'title'=>'Test','status'=>'published','price'=>0]);
    $content=Content::create(['lesson_id'=>$lesson->id,'type'=>'pdf','title'=>'Local test','status'=>'published','price'=>0]);
    $key='worker-e2e/'.basename($db).'/'.$i.'.pdf';
    Storage::disk('local')->put($key,$i===0 ? '%PDF-broken' : file_get_contents($fixture));
    $ids[]=ContentAsset::create(['content_id'=>$content->id,'disk'=>'local','storage_key'=>$key,
        'mime_type'=>'application/pdf','status'=>'active','processing_status'=>'pending'])->id;
}
$start=microtime(true);
$first=new Process([PHP_BINARY,$root.'/artisan','pdf:process-pending','--limit=20'],$root,$vars);
$first->setTimeout(600);$first->start();
$second=new Process([PHP_BINARY,$root.'/artisan','pdf:process-pending','--limit=20'],$root,$vars);
$second->setTimeout(600);$second->start();
$first->wait();$second->wait();
$assert($first->isSuccessful()&&$second->isSuccessful(),'both_worker_processes_exit_cleanly');
$outputs=$first->getOutput().$second->getOutput();
$assert(str_contains($outputs,'already running'),'second_os_worker_does_not_duplicate_work');
$assert(ContentAsset::where('processing_status','ready')->count()===10,'ten_real_pdfs_ready');
$assert(ContentAsset::where('processing_status','failed')->count()===1,'broken_pdf_does_not_block_ten_teachers');
$assert((int)ContentAsset::sum('processing_attempts')===11,'exactly_one_attempt_per_document');
preg_match_all('/PDF (\d+):/',$outputs,$matches);
$assert(array_map('intval',$matches[1])===$ids,'actual_subprocesses_follow_fifo_order');
$pages=0;
foreach(ContentAsset::where('processing_status','ready')->get() as $a){
    $assert($a->total_pages===4,'four_pages_asset_'.$a->id);
    $assert(str_starts_with(Storage::disk('local')->get($a->storage_key),'LQPDF2'),'encrypted_asset_'.$a->id);
    for($p=1;$p<=4;$p++){
        $jpg=Storage::disk('local')->get('pdf-pages/'.$a->id.'/v'.$a->processing_version.'/'.$p.'.jpg');
        if(!str_starts_with($jpg,hex2bin('ffd8')))throw new RuntimeException('Invalid JPEG');
        $pages++;
    }
}
$student=Student::create(['user_id'=>User::factory()->create(['role'=>'student','status'=>'active'])->id,'status'=>'active']);
$asset=ContentAsset::where('processing_status','ready')->firstOrFail();
Enrollment::create(['student_id'=>$student->id,'teacher_id'=>$asset->content->lesson->unit->teacherSubject->teacher_id,
    'enrollable_type'=>'content','enrollable_id'=>$asset->content_id,'price'=>0,'status'=>'active']);
auth()->setUser($student->user);
$request=Illuminate\Http\Request::create('https://example.test/api/v1/pdf','POST');
$request->setUserResolver(fn()=>$student->user);$app->instance('request',$request);
$controller=app(App\Http\Controllers\Api\V1\SecurePdfController::class);
$open=$controller->open($request,$asset)->getData(true)['data'];
$request->headers->set('X-PDF-Session',$open['session_token']);
$image=$controller->page($request,$open['session_reference'],4,app(App\Services\SecurePdfProcessor::class))->getData(true)['data'];
$assert(str_starts_with(base64_decode($image['image']),hex2bin('ffd8')),'student_page_four_watermarked_jpeg');
$assert(hash('sha256',base64_decode($image['image']))!==hash('sha256',Storage::disk('local')->get('pdf-pages/'.$asset->id.'/v'.$asset->processing_version.'/4.jpg')),'watermark_changes_image');
$result=['status'=>'passed','checks'=>$checks,'real_page_images'=>$pages,'seconds'=>round(microtime(true)-$start,2),
    'peak_parent_memory_mb'=>round(memory_get_peak_usage(true)/1048576,2),'worker_output'=>$outputs];
file_put_contents($root.'/storage/framework/testing/pdf-worker-smoke-latest.json',json_encode($result,JSON_PRETTY_PRINT));
echo json_encode($result,JSON_PRETTY_PRINT).PHP_EOL;

Illuminate\Support\Facades\DB::disconnect('sqlite');
$allowedRoot = realpath($root.'/storage/framework/testing');
$resolvedSandbox = realpath($sandbox);
if ($allowedRoot && $resolvedSandbox && str_starts_with($resolvedSandbox, $allowedRoot.DIRECTORY_SEPARATOR)) {
    Illuminate\Support\Facades\File::deleteDirectory($resolvedSandbox);
}
