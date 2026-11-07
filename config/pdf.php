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
    'processing_timeout_seconds' => (int) env('PDF_PROCESSING_TIMEOUT_SECONDS', 300),
    'worker_budget_seconds' => (int) env('PDF_WORKER_BUDGET_SECONDS', 50),
    'max_processing_attempts' => (int) env('PDF_MAX_PROCESSING_ATTEMPTS', 3),
    'retry_delay_seconds' => (int) env('PDF_RETRY_DELAY_SECONDS', 60),
    'max_document_pages' => (int) env('PDF_MAX_DOCUMENT_PAGES', 200),
    'max_source_bytes' => 25 * 1024 * 1024 + 4096,
    'max_render_bytes' => 128 * 1024 * 1024,

];
