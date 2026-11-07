<?php
namespace App\Services;

use App\Contracts\PdfRenderer;
use RuntimeException;

class ImagickPdfRenderer implements PdfRenderer
{
    public function render(string $source, string $outputDirectory): int
    {
        if (! extension_loaded('imagick') || ! class_exists(\Imagick::class)) throw new RuntimeException('PDF_RENDERER_UNAVAILABLE');
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_THREAD, 1);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 128 * 1024 * 1024);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, 256 * 1024 * 1024);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_DISK, 512 * 1024 * 1024);
        $probe = new \Imagick();
        try {
            $probe->setResolution(config('pdf.dpi'), config('pdf.dpi'));
            $probe->pingImage($source);
            $pages = $probe->getNumberImages();
        } catch (\Throwable $e) {
            throw new RuntimeException('PDF_RENDER_INVALID', 0, $e);
        } finally { $probe->clear(); $probe->destroy(); }
        if ($pages < 1 || $pages > max(1, (int) config('pdf.max_document_pages', 200))) throw new RuntimeException('PDF_RENDER_PAGE_LIMIT');

        $bytes = 0;
        for ($index = 0; $index < $pages; $index++) {
            $page = new \Imagick();
            try {
                $page->setResolution(config('pdf.dpi'), config('pdf.dpi'));
                $page->readImage($source.'['.$index.']');
                $page->setImageBackgroundColor('white');
                $page->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                $page->setImageFormat('jpeg');
                $page->setImageCompressionQuality(88);
                $number = str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
                if (! $page->writeImage($outputDirectory.DIRECTORY_SEPARATOR.'page-'.$number.'.jpg')) throw new RuntimeException('PDF_RENDER_FAILED');
                $bytes += filesize($outputDirectory.DIRECTORY_SEPARATOR.'page-'.$number.'.jpg');
                if ($bytes > config('pdf.max_render_bytes')) throw new RuntimeException('PDF_RENDER_SIZE_LIMIT');
            } catch (\Throwable $e) {
                throw new RuntimeException('PDF_RENDER_FAILED', 0, $e);
            } finally { $page->clear(); $page->destroy(); }
        }
        return $pages;
    }
}
