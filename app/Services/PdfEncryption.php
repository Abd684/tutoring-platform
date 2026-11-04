<?php

namespace App\Services;

use RuntimeException;

class PdfEncryption
{
    private function key(): string
    {
        $key = config('pdf.master_key');
        if (! is_string($key) || strlen(base64_decode($key, true) ?: '') !== 32) {
            throw new RuntimeException('PDF encryption key is not configured.');
        }
        return base64_decode($key, true);
    }

    public function encrypt(string $plain, string $encrypted): void
    {
        $fileKey = random_bytes(32); $wrapIv = random_bytes(12); $wrapTag = '';
        $wrappedKey = openssl_encrypt($fileKey, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $wrapIv, $wrapTag, '', 16);
        if ($wrappedKey === false) throw new RuntimeException('PDF key wrapping failed.');
        $iv = random_bytes(12); $tag = '';
        $input = fopen($plain, 'rb'); $output = fopen($encrypted, 'wb');
        if (! $input || ! $output) throw new RuntimeException('Cannot open PDF encryption stream.');
        try {
            fwrite($output, "LQPDF2".$wrapIv.$wrappedKey.$wrapTag.$iv);
            $buffer = ''; $chunkNumber = 0;
            while (! feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false) throw new RuntimeException('Cannot read PDF source.');
                $buffer .= $chunk;
                while (strlen($buffer) >= 1024 * 1024) {
                    $part = substr($buffer, 0, 1024 * 1024); $buffer = substr($buffer, 1024 * 1024);
                    $cipher = openssl_encrypt($part, 'aes-256-gcm', $fileKey, OPENSSL_RAW_DATA, $iv, $tag, pack('N', $chunkNumber++), 16);
                    if ($cipher === false) throw new RuntimeException('PDF encryption failed.');
                    fwrite($output, pack('N', strlen($cipher)).$cipher.$tag); $iv = $this->nextIv($iv);
                }
            }
            if ($buffer !== '') {
                $cipher = openssl_encrypt($buffer, 'aes-256-gcm', $fileKey, OPENSSL_RAW_DATA, $iv, $tag, pack('N', $chunkNumber), 16);
                if ($cipher === false) throw new RuntimeException('PDF encryption failed.');
                fwrite($output, pack('N', strlen($cipher)).$cipher.$tag);
            }
        } finally { fclose($input); fclose($output); }
    }

    public function decrypt(string $encrypted, string $plain): void
    {
        $in = fopen($encrypted, 'rb'); $out = fopen($plain, 'wb');
        if (! $in || ! $out || fread($in, 6) !== 'LQPDF2') throw new RuntimeException('Encrypted PDF format is invalid.');
        $wrapIv=fread($in,12); $wrappedKey=fread($in,32); $wrapTag=fread($in,16);
        if (strlen($wrapIv)!==12 || strlen($wrappedKey)!==32 || strlen($wrapTag)!==16) throw new RuntimeException('Encrypted PDF key header is invalid.');
        $fileKey=openssl_decrypt($wrappedKey,'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,$wrapIv,$wrapTag);
        if ($fileKey===false || strlen($fileKey)!==32) throw new RuntimeException('Encrypted PDF key authentication failed.');
        $iv = fread($in, 12);
        try {
            $chunkNumber = 0;
            while (! feof($in)) {
                $length = fread($in, 4); if ($length === '' || $length === false) break;
                if (strlen($length) !== 4) throw new RuntimeException('Encrypted PDF is truncated.');
                $size = unpack('N', $length)[1]; $cipher = ($size ? fread($in, $size) : ''); $tag = fread($in, 16);
                if (strlen($cipher) !== $size || strlen($tag) !== 16) throw new RuntimeException('Encrypted PDF is truncated.');
                $data = openssl_decrypt($cipher, 'aes-256-gcm', $fileKey, OPENSSL_RAW_DATA, $iv, $tag, pack('N', $chunkNumber++));
                if ($data === false) throw new RuntimeException('Encrypted PDF authentication failed.');
                fwrite($out, $data); $iv = $this->nextIv($iv);
            }
        } finally { fclose($in); fclose($out); }
    }
    private function nextIv(string $iv): string { $n = unpack('N', substr($iv, 8, 4))[1]; return substr($iv, 0, 8).pack('N', ($n + 1) & 0xffffffff); }
}
