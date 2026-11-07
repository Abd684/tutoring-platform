<?php

namespace App\Services;

use App\Models\Content;
use App\Models\ContentAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class PdfAssetRegistration
{
    /** Repeated uploads reuse the active PDF; distinct PDFs remain separate. */
    public function register(Content $content, array $attributes, bool $verifiedChecksum = false): ContentAsset
    {
        return DB::transaction(function () use ($content, $attributes, $verifiedChecksum) {
            // Both admin and teacher uploads serialize on the same parent, including the first upload.
            Content::whereKey($content->id)->lockForUpdate()->firstOrFail();
            $checksum = $attributes['checksum'] ?? null;
            $trustHash = $verifiedChecksum && is_string($checksum) && preg_match('/^[a-f0-9]{64}$/i', $checksum);
            $candidates = ContentAsset::where('content_id', $content->id)
                ->where('mime_type', 'application/pdf')->where('status', 'active')
                ->where(function ($q) use ($attributes, $trustHash, $checksum) {
                    $q->where(fn ($q) => $q->where('disk', $attributes['disk'])->where('storage_key', $attributes['storage_key']));
                    if ($trustHash) $q->orWhere('checksum', $checksum);
                })
                ->orderByRaw("CASE WHEN processing_status = 'ready' THEN 0 ELSE 1 END")
                ->orderByDesc('id')->get();
            foreach ($candidates as $existing) {
                if (! Storage::disk($existing->disk)->exists($existing->storage_key)) continue;
                $samePath = $existing->disk === $attributes['disk'] && $existing->storage_key === $attributes['storage_key'];
                if ($samePath || ($trustHash && $this->matches($existing, $checksum))) return $existing;
            }
            // Storage-key registration accepts client metadata, not proof of file contents.
            if (! $verifiedChecksum) $attributes['checksum'] = null;
            return ContentAsset::create(['content_id' => $content->id, ...$attributes]);
        });
    }

    private function matches(ContentAsset $asset, string $checksum): bool
    {
        $disk = Storage::disk($asset->disk);
        if ($disk->size($asset->storage_key) > 25 * 1024 * 1024 + 4096) return false;
        $work = storage_path('app/private/pdf-dedup/'.Str::uuid());
        File::ensureDirectoryExists($work);
        try {
            file_put_contents($work.'/stored', $disk->get($asset->storage_key));
            $path = $work.'/stored';
            if (file_get_contents($path, false, null, 0, 6) === 'LQPDF2') {
                app(PdfEncryption::class)->decrypt($path, $work.'/plain');
                $path = $work.'/plain';
            }
            return hash_equals(strtolower($checksum), hash_file('sha256', $path));
        } catch (\Throwable $e) {
            return false; // An unreadable old copy must not mask a healthy new upload.
        } finally {
            File::deleteDirectory($work);
        }
    }
}
