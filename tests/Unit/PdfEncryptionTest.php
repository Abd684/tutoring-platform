<?php

namespace Tests\Unit;

use App\Services\PdfEncryption;
use Tests\TestCase;

class PdfEncryptionTest extends TestCase
{
    public function test_encrypted_pdf_round_trips_and_ciphertext_does_not_contain_source(): void
    {
        config(['pdf.master_key' => base64_encode(random_bytes(32))]);
        $dir = sys_get_temp_dir().'/liqa-pdf-'.bin2hex(random_bytes(6)); mkdir($dir);
        $plain = $dir.'/source.pdf'; $encrypted = $dir.'/source.enc'; $restored = $dir.'/restored.pdf';
        $contents = "%PDF-1.7\n".str_repeat('private-document-content-', 60000);
        file_put_contents($plain, $contents);
        try {
            app(PdfEncryption::class)->encrypt($plain, $encrypted);
            $this->assertStringStartsWith('LQPDF2', file_get_contents($encrypted, false, null, 0, 6));
            $this->assertStringNotContainsString('private-document-content', file_get_contents($encrypted));
            app(PdfEncryption::class)->decrypt($encrypted, $restored);
            $this->assertSame($contents, file_get_contents($restored));
        } finally { foreach ([$plain,$encrypted,$restored] as $file) if (is_file($file)) unlink($file); rmdir($dir); }
    }
}
