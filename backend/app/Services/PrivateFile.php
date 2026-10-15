<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/** Delivers private-storage files through an authorised request: short signed URL on S3, streamed response locally. */
class PrivateFile
{
    public static function send(string $path, string $downloadName)
    {
        $disk = Storage::disk('private');
        abort_unless($disk->exists($path), 404);
        if (config('filesystems.disks.private.driver') === 's3') {
            return redirect()->away($disk->temporaryUrl($path, now()->addMinutes(5), ['ResponseContentDisposition' => 'attachment; filename="'.addslashes($downloadName).'"']));
        }

        return $disk->download($path, $downloadName);
    }
}
