<?php

namespace App\Support;

class BrandAsset
{
    public static function src(string $filename): string
    {
        $path = public_path('images/brand/'.basename($filename));

        if (app()->environment('local')) {
            return 'data:image/webp;base64,'.base64_encode((string) file_get_contents($path));
        }

        return asset('images/brand/'.basename($filename)).'?v='.filemtime($path);
    }
}
