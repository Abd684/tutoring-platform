<?php
namespace App\Contracts;
interface PdfRenderer { public function render(string $source, string $outputDirectory): int; }
