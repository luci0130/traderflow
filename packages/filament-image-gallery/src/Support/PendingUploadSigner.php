<?php

namespace Turiac\FilamentImageGallery\Support;

use Illuminate\Support\Facades\Crypt;
use Throwable;

class PendingUploadSigner
{
    public function sign(string $disk, string $path): string
    {
        return Crypt::encrypt([
            'disk' => $disk,
            'expires_at' => now()->addHours(24)->getTimestamp(),
            'path' => $path,
        ]);
    }

    public function isValid(?string $signature, string $disk, string $path): bool
    {
        if (blank($signature)) {
            return false;
        }

        try {
            $payload = Crypt::decrypt($signature);

            return is_array($payload)
                && ($payload['expires_at'] ?? 0) >= now()->getTimestamp()
                && hash_equals($disk, (string) ($payload['disk'] ?? ''))
                && hash_equals($path, (string) ($payload['path'] ?? ''));
        } catch (Throwable) {
            return false;
        }
    }
}
