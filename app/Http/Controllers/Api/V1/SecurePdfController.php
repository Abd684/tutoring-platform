<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\ContentAsset;
use App\Models\PdfViewingSession;
use App\Services\SecurePdfProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SecurePdfController extends ApiController
{
    public function open(Request $request, ContentAsset $document): JsonResponse
    {
        $request->validate(['device_identifier'=>['nullable','string','max:160']]);
        abort_unless($document->mime_type === 'application/pdf' && $document->processing_status === 'ready', 409, 'PDF processing is not ready.');
        $this->authorize('accessStudent', \App\Models\Content::class); $this->authorize('access', $document->content);
        $token = Str::random(64); $now = now();
        $session = PdfViewingSession::create(['public_reference'=>(string) Str::uuid(),'user_id'=>$request->user()->id,'document_id'=>$document->id,'token_hash'=>hash('sha256',$token),'device_identifier'=>$this->currentDevice($request),'status'=>'active','started_at'=>$now,'last_activity_at'=>$now,'expires_at'=>$now->copy()->addMinutes(config('pdf.session_minutes'))]);
        return $this->success(['session_reference'=>$session->public_reference,'session_token'=>$token,'session_expires_at'=>$session->expires_at->toIso8601String(),'document'=>['reference'=>'DOC-'.strtoupper(substr(hash('sha256',(string)$document->id),0,12)),'title'=>$document->content->title,'total_pages'=>$document->total_pages],'initial_pages'=>range(1,min(3,$document->total_pages))])->header('Cache-Control','private, no-store');
    }

    public function page(Request $request, string $session, int $page, SecurePdfProcessor $pdf): JsonResponse
    {
        $view = $this->activeSession($request, $session); abort_unless($page >= 1 && $page <= $view->document->total_pages, 404);
        $asset = $view->document; $this->authorize('access', $asset->content);
        $path = 'pdf-pages/'.$asset->id.'/v'.$asset->processing_version.'/'.$page.'.jpg'; abort_unless(Storage::disk($asset->disk)->exists($path), 404);
        $student = $request->user()->student; $account = 'LIQ-'.strtoupper(substr(hash('sha256',(string)$student->id),0,8));
        $image = $pdf->watermark(Storage::disk($asset->disk)->get($path),$request->user()->name,$account,now()->format('Y-m-d H:i'),$view->public_reference.':'.$page);
        $view->update(['last_activity_at'=>now()]);
        return $this->success(['page_number'=>$page,'total_pages'=>$asset->total_pages,'mime_type'=>'image/jpeg','image'=>base64_encode($image)])->header('Cache-Control','private, no-store');
    }

    public function pages(Request $request, string $session, SecurePdfProcessor $pdf): JsonResponse
    {
        $data=$request->validate(['from'=>['required','integer','min:1'],'to'=>['required','integer','gte:from']]); abort_if($data['to']-$data['from']+1>config('pdf.max_pages_per_request'),422,'Too many pages requested.');
        $view=$this->activeSession($request,$session); abort_unless($data['to'] <= $view->document->total_pages,404);
        $items=[]; for($n=$data['from'];$n<=$data['to'];$n++) $items[]=$this->pageImage($request,$view,$n,$pdf);
        $view->update(['last_activity_at'=>now()]); return $this->success(['pages'=>$items])->header('Cache-Control','private, no-store');
    }

    private function pageImage(Request $request, PdfViewingSession $view, int $page, SecurePdfProcessor $pdf): array
    {
        $asset=$view->document; $this->authorize('access',$asset->content); $path='pdf-pages/'.$asset->id.'/v'.$asset->processing_version.'/'.$page.'.jpg'; abort_unless(Storage::disk($asset->disk)->exists($path),404);
        $account='LIQ-'.strtoupper(substr(hash('sha256',(string)$request->user()->student->id),0,8));
        return ['page_number'=>$page,'mime_type'=>'image/jpeg','image'=>base64_encode($pdf->watermark(Storage::disk($asset->disk)->get($path),$request->user()->name,$account,now()->format('Y-m-d H:i'),$view->public_reference.':'.$page))];
    }

    public function show(Request $request,string $session): JsonResponse { $s=$this->activeSession($request,$session); return $this->success(['status'=>$s->status,'total_pages'=>$s->document->total_pages,'expires_at'=>$s->expires_at->toIso8601String()])->header('Cache-Control','private, no-store'); }
    public function close(Request $request,string $session): JsonResponse { $s=PdfViewingSession::where('public_reference',$session)->where('user_id',$request->user()->id)->firstOrFail(); $s->update(['status'=>'revoked','revoked_at'=>now()]); return $this->success(['status'=>'revoked']); }

    private function activeSession(Request $request,string $reference): PdfViewingSession
    {
        $s=PdfViewingSession::with('document.content')->where('public_reference',$reference)->where('user_id',$request->user()->id)->firstOrFail();
        $token=(string)$request->header('X-PDF-Session'); abort_unless($s->status==='active' && $s->expires_at->isFuture() && hash_equals($s->token_hash,hash('sha256',$token)),403);
        abort_unless($s->document->processing_status === 'ready', 404);
        abort_unless($s->device_identifier === null || hash_equals($s->device_identifier, (string) $this->currentDevice($request)), 403);
        $this->authorize('accessStudent',\App\Models\Content::class); $this->authorize('access',$s->document->content); return $s;
    }

    private function currentDevice(Request $request): ?string
    {
        $name = $request->user()->currentAccessToken()?->name;
        return is_string($name) && preg_match('/^student_device_(\d+)_session_\d+$/', $name, $matches) ? 'student-device:'.$matches[1] : null;
    }
}
