<?php
namespace App\Services;

use App\Contracts\PdfRenderer;
use RuntimeException;

class ImagickPdfRenderer implements PdfRenderer
{
    public function render(string $source, string $outputDirectory): int
    {
        if (! extension_loaded('imagick') || ! class_exists(\Imagick::class)) throw new RuntimeException('PDF_RENDERER_UNAVAILABLE');
        $probe = new \Imagick();
        try {
            $probe->setResolution(config('pdf.dpi'), config('pdf.dpi'));
            $probe->pingImage($source);
            $pages = $probe->getNumberImages();
        } catch (\Throwable $e) {
            throw new RuntimeException('PDF_RENDER_INVALID', 0, $e);
        } finally { $probe->clear(); $probe->destroy(); }
        if ($pages < 1 || $pages > 2000) throw new RuntimeException('PDF_RENDER_PAGE_LIMIT');

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
            } catch (\Throwable $e) {
                throw new RuntimeException('PDF_RENDER_FAILED', 0, $e);
            } finally { $page->clear(); $page->destroy(); }
        }
        return $pages;
    }
}
