<?php
namespace App\Services;

use App\Contracts\PdfRenderer;
use RuntimeException;
use Symfony\Component\Process\Process;

class PopplerPdfRenderer implements PdfRenderer
{
    public function render(string $source, string $outputDirectory): int
    {
        $info = new Process([config('pdf.pdfinfo'), $source]); $info->setTimeout(120); $info->run();
        if (! $info->isSuccessful() || ! preg_match('/^Pages:\s+(\d+)/m', $info->getOutput(), $match)) throw new RuntimeException('PDF_RENDER_INVALID');
        $pages = (int) $match[1]; if ($pages < 1 || $pages > max(1, (int) config('pdf.max_document_pages', 200))) throw new RuntimeException('PDF_RENDER_PAGE_LIMIT');
        $process = new Process([config('pdf.pdftoppm'), '-jpeg', '-jpegopt', 'quality=88', '-r', (string) config('pdf.dpi'), $source, $outputDirectory.DIRECTORY_SEPARATOR.'page']);
        $process->setTimeout(600); $process->run();
        if (! $process->isSuccessful()) throw new RuntimeException('PDF_RENDER_FAILED');
        return $pages;
    }
}
