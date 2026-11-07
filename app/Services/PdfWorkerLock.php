<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

/** OS locks survive cache resets and are released automatically after a crash. */
class PdfWorkerLock
{
    public function acquire(string $name)
    {
        $directory = storage_path('framework/pdf-locks');
        File::ensureDirectoryExists($directory);
        $handle = fopen($directory.'/'.$name.'.lock', 'c');
        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) fclose($handle);
            return null;
        }
        return $handle;
    }

    public function release($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
