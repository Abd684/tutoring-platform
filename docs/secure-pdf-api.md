# Protected PDF API

All endpoints require a Sanctum student bearer token and the active student device middleware. The view token is separate and must be sent as `X-PDF-Session`. Responses containing page images use base64 JPEG and `Cache-Control: private, no-store`.

1. `POST /api/v1/pdf/{contentAssetId}/view` with optional `device_identifier` opens a session. It returns a public session reference, a random 64-character token, expiry, document metadata, and up to three initial page numbers.
2. `GET /api/v1/pdf/sessions/{reference}/pages/{page}` returns one watermarked JPEG.
3. `GET /api/v1/pdf/sessions/{reference}/pages?from=1&to=3` returns at most three watermarked pages.
4. `GET /api/v1/pdf/sessions/{reference}` returns current status and expiry.
5. `DELETE /api/v1/pdf/sessions/{reference}` revokes the session.

The session reference is not an authenticator. Every request checks the authenticated owner, token hash, expiry, revocation status, current course access, current device-bound student session, and page range. The stored device binding comes from the authenticated device token; the client supplied identifier is never treated as authentication. The legacy PDF playback and access-token routes return HTTP 410. PDF uploads remain at the existing teacher upload route, are capped at 25 MiB, checked by MIME and signature, then encrypted with a random per-file data key using AES-256-GCM. The data key is wrapped by the separately configured master key.

## Deployment

Set `PDF_RENDERER=imagick` on hosts with PHP Imagick and Ghostscript support, or use `PDF_RENDERER=poppler` with `PDFTOPPM_BINARY` and `PDFINFO_BINARY`. Set `PDF_MASTER_KEY` to a base64-encoded random 32-byte key kept outside source control and separate from the database, then run migrations. Set `PDF_WATERMARK_FONT` to a readable Unicode TTF font outside the public directory for student names in Arabic or other non-Latin scripts. A cron entry must run `php artisan pdf:process-pending --limit=5` regularly; only `ready` files can open. `PDF_RENDER_DPI` defaults to 130. Retain the key securely; losing it makes originals unrecoverable. Key rotation requires a controlled re-encryption procedure.

The renderer is bound through `App\Contracts\PdfRenderer` and can be replaced. Do not expose `storage/app/private` or the configured media disk through the web server.
