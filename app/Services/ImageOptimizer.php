<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

/**
 * Resizes + re-encodes uploaded product images before storing, using
 * PHP's built-in GD extension (no Intervention/Spatie dependency).
 * Re-encoding also strips EXIF data as a side effect.
 */
class ImageOptimizer
{
    protected int $maxDimension;
    protected int $quality;

    public function __construct(int $maxDimension = 1600, int $quality = 82)
    {
        $this->maxDimension = $maxDimension;
        $this->quality = $quality;
    }

    /**
     * Store the uploaded file on the given disk/path, resized + re-encoded
     * as JPEG. Falls back to storing the original file untouched if GD
     * can't read it (e.g. unsupported format) so upload never hard-fails
     * because of optimization.
     */
    public function storeOptimized(UploadedFile $file, string $disk, string $directory): string
    {
        $source = $this->readImage($file->getRealPath(), $file->getClientOriginalExtension());

        if (! $source) {
            return $file->store($directory, $disk);
        }

        try {
            [$width, $height] = [imagesx($source), imagesy($source)];
            $scale = min(1, $this->maxDimension / max($width, $height));

            if ($scale < 1) {
                $newWidth  = (int) round($width * $scale);
                $newHeight = (int) round($height * $scale);

                $resized = imagecreatetruecolor($newWidth, $newHeight);
                imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                imagedestroy($source);
                $source = $resized;
            }

            ob_start();
            imagejpeg($source, null, $this->quality);
            $contents = ob_get_clean();
        } finally {
            imagedestroy($source);
        }

        $path = trim($directory, '/') . '/' . uniqid('', true) . '.jpg';

        \Illuminate\Support\Facades\Storage::disk($disk)->put($path, $contents);

        return $path;
    }

    /** @return \GdImage|null */
    protected function readImage(string $path, string $extension)
    {
        return match (strtolower($extension)) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($path) ?: null,
            'png'         => @imagecreatefrompng($path) ?: null,
            'webp'        => function_exists('imagecreatefromwebp') ? (@imagecreatefromwebp($path) ?: null) : null,
            'gif'         => @imagecreatefromgif($path) ?: null,
            default       => null,
        };
    }
}
