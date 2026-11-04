# Secure PDF hosting compatibility report

## Checked environment

The development PHP runtime has OpenSSL and GD, but no Imagick extension. Poppler `pdfinfo` and `pdftoppm` are available in the Codex runtime only. The hosting shell check provided by the operator reports OpenSSL, GD, and Imagick enabled, `pdfinfo`/`pdftoppm` unavailable, PHP memory limit 512 MiB, and CLI execution time unlimited. A real hosted PDF was successfully opened through Imagick/Ghostscript at 120 DPI (first page rendered at 992×1403). This confirms a viable renderer on that host; it does not yet measure a full document or prove the Laravel cron PHP uses the identical extension configuration.

## Production gate

The renderer test confirms Imagick/Ghostscript can render at least one real PDF page. Configure `PDF_RENDERER=imagick` on the host, then run a representative multi-page asset through the full `pdf:process-pending` workflow. Confirm private writable storage, cron frequency, available memory, and that the PHP executable used by cron has Imagick enabled. Until processing succeeds, assets stay `pending` or `failed` and the viewer rejects them.

## Performance limits and measurements

No production performance benchmark has been run. Rendering is precomputed by CLI/cron, not on page requests. The initial configuration renders JPEG at 130 DPI; each requested page is watermarked in GD. Page batch requests are limited to three. Actual processing time and peak memory must be measured on the target host with representative large PDFs before setting cron batch size and DPI. The original is read into memory during processing, so the 25 MiB upload cap leaves headroom under the checked 512 MiB development limit; confirm production memory limits before enabling uploads.

## Shared-hosting prerequisites

- OpenSSL and GD PHP extensions.
- PHP Imagick with Ghostscript PDF support, or Poppler binaries executable by the PHP account.
- Private media storage and private temporary storage with sufficient free space.
- Cron access to invoke `php artisan pdf:process-pending`.
- `PDF_MASTER_KEY` securely configured outside the repository.

If the host cannot provide a usable renderer or cron execution, do not claim the protection workflow is production-ready; choose a supported renderer/hosting plan first.
