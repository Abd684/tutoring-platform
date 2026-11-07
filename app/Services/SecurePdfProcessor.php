<?php
namespace App\Services;

use App\Contracts\PdfRenderer;
use App\Models\ContentAsset;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SecurePdfProcessor
{
    public function __construct(private PdfRenderer $renderer, private PdfEncryption $encryption) {}

    public function process(ContentAsset $asset): void
    {
        $work = storage_path('app/private/pdf-work/asset-'.$asset->id);
        File::deleteDirectory($work);
        File::ensureDirectoryExists($work);
        $disk = Storage::disk($asset->disk);
        $asset->update(['processing_status'=>'processing','processing_error_code'=>null]);
        try {
            if ($disk->size($asset->storage_key) > config('pdf.max_source_bytes')) throw new RuntimeException('PDF_SOURCE_SIZE_LIMIT');
            $encrypted = $work.'/source.enc'; $plain = $work.'/source.pdf';
            file_put_contents($encrypted, $disk->get($asset->storage_key));
            if (substr(file_get_contents($encrypted, false, null, 0, 6), 0, 6) !== 'LQPDF2') {
                $this->encryption->encrypt($encrypted, $work.'/encrypted-new');
                $disk->put($asset->storage_key, fopen($work.'/encrypted-new', 'rb'));
                rename($work.'/encrypted-new', $encrypted);
            }
            $this->encryption->decrypt($encrypted, $plain);
            if (! str_starts_with((string) file_get_contents($plain, false, null, 0, 5), '%PDF-')) throw new RuntimeException('PDF_INVALID');
            $renderDir = $work.'/pages'; File::ensureDirectoryExists($renderDir);
            $pages = $this->renderer->render($plain, $renderDir);
            if ($pages < 1 || $pages > max(1, (int) config('pdf.max_document_pages', 200))) throw new RuntimeException('PDF_RENDER_PAGE_LIMIT');
            $prefix = 'pdf-pages/'.$asset->id.'/v'.($asset->processing_version ?? 1);
            $disk->deleteDirectory('pdf-pages/'.$asset->id);
            $rendered = glob($renderDir.'/page-*.jpg') ?: []; natsort($rendered);
            $bytes = 0;
            foreach (array_values($rendered) as $i => $path) {
                $bytes += filesize($path);
                if ($bytes > config('pdf.max_render_bytes')) throw new RuntimeException('PDF_RENDER_SIZE_LIMIT');
                $pageNo = $i + 1; if (! $disk->put($prefix.'/'.$pageNo.'.jpg', file_get_contents($path))) throw new RuntimeException('PDF_PAGE_STORAGE_FAILED');
            }
            if (count($rendered) !== $pages) throw new RuntimeException('PDF_RENDER_INCOMPLETE');
            $asset->update(['processing_status'=>'ready','total_pages'=>$pages,'processed_at'=>now(),'encryption_type'=>'AES-256-GCM-ENVELOPE','encryption_status'=>'done']);
        } catch (Throwable $e) {
            $asset->update(['processing_status'=>'failed','processing_error_code'=>preg_match('/^PDF_[A-Z_]+$/', $e->getMessage()) ? $e->getMessage() : 'PDF_PROCESSING_FAILED']);
            report($e);
        } finally { File::deleteDirectory($work); }
    }

    public function watermark(string $jpeg, string $name, string $account, string $viewedAt, string $seed): string
    {
        $image = imagecreatefromstring($jpeg); if (! $image) throw new RuntimeException('Cannot load rendered PDF page.');
        $width = imagesx($image); $height = imagesy($image); $color = imagecolorallocatealpha($image, 55, 55, 55, 70);
        $text = mb_substr($name.' | '.$account.' | '.$viewedAt, 0, 220);
        $stepX = (int) max(220, $width * .55); $stepY = (int) max(150, $height * .30);
        $offsetX = hexdec(substr(hash('sha256', $seed), 0, 4)) % $stepX;
        $offsetY = hexdec(substr(hash('sha256', $seed), 4, 4)) % $stepY;
        $font = config('pdf.watermark_font');
        for ($y = -$stepY + $offsetY; $y < $height; $y += $stepY) for ($x = -$stepX + $offsetX; $x < $width; $x += $stepX) {
            if (is_string($font) && is_file($font) && function_exists('imagettftext')) imagettftext($image, max(10, (int) round($width / 100)), 0, $x, $y + 18, $color, $font, $text);
            else imagestring($image, 4, $x, $y, $text, $color);
        }
        ob_start(); imagejpeg($image, null, 88); $output = ob_get_clean(); imagedestroy($image); return $output;
    }
}
