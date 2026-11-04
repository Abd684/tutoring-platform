<?php
return [
    'master_key' => env('PDF_MASTER_KEY'),
    'renderer' => env('PDF_RENDERER', 'poppler'),
    'pdftoppm' => env('PDFTOPPM_BINARY', 'pdftoppm'),
    'pdfinfo' => env('PDFINFO_BINARY', 'pdfinfo'),
    'dpi' => (int) env('PDF_RENDER_DPI', 130),
    'watermark_font' => env('PDF_WATERMARK_FONT'),
    'max_pages_per_request' => 3,
    'session_minutes' => 30,
];
