<?php

namespace App\Services\Media;

use App\Models\ProjectMedia;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * GD-based image processing: orientation fix, EXIF/location strip via
 * re-encoding, and bounded responsive derivatives. Re-encoding (never
 * copying) guarantees no metadata survives; derivatives are JPEG,
 * which carries no EXIF in our output.
 */
final class ImageProcessor
{
    /** @var array<string, int> variant => max side in px */
    public const VARIANTS = ['thumb' => 400, 'card' => 800, 'wide' => 1200];

    public static function process(ProjectMedia $media): void
    {
        // Environmental preflight: the worker that picks this job up
        // must have GD. Without it the bytes may be perfectly valid,
        // so this failure stays retryable instead of condemning the row.
        if (! function_exists('imagecreatefromstring')) {
            throw new ProcessingUnavailableException('Image processing is unavailable on this worker.');
        }

        $disk = Storage::disk('local');
        $bytes = $disk->get($media->originalPath());

        if ($bytes === null || $bytes === '') {
            throw new UnprocessableImageException('Stored image is missing.', ProjectMedia::ERROR_MISSING_ORIGINAL);
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            throw new UnprocessableImageException('Stored image is unreadable.');
        }

        [$width, $height] = $info;
        if ($width * $height > MediaUploadService::MAX_PIXELS) {
            throw new UnprocessableImageException('Image exceeds the pixel budget.');
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new UnprocessableImageException('Image could not be decoded.');
        }

        try {
            $source = self::applyOrientation($source, $bytes);
            $width = imagesx($source);
            $height = imagesy($source);

            $paths = [];
            foreach (self::VARIANTS as $variant => $maxSide) {
                $paths[$variant] = self::renderVariant($disk, $media, $source, $width, $height, $variant, $maxSide);
            }

            // Re-encode the original too: same pixels, no metadata.
            $clean = self::encodeJpeg($source, $width, $height, 90);
            $disk->put($media->originalPath(), $clean);

            $media->forceFill([
                'width' => $width,
                'height' => $height,
                'mime' => 'image/jpeg',
                'size' => strlen($clean),
                'sha256' => hash('sha256', $clean),
                'processing_status' => ProjectMedia::STATUS_READY,
            ])->save();
        } finally {
            imagedestroy($source);
        }
    }

    private static function applyOrientation(\GdImage $source, string $bytes): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $source;
        }

        try {
            $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        } catch (\Throwable) {
            return $source;
        }

        $orientation = (int) ($exif['Orientation'] ?? 1);
        if ($orientation < 2 || $orientation > 8) {
            return $source;
        }

        // Orientations 2,4,5,7 need mirroring; GD lacks a flip before
        // imageflip(), which is universally available in PHP 8.
        $flipped = match ($orientation) {
            2, 4 => self::flip($source, IMG_FLIP_HORIZONTAL),
            5, 7 => self::flip($source, IMG_FLIP_VERTICAL),
            default => $source,
        };

        $rotated = match ($orientation) {
            3, 4 => imagerotate($flipped, 180, 0),
            5, 6 => imagerotate($flipped, -90, 0),
            7, 8 => imagerotate($flipped, 90, 0),
            default => $flipped,
        };

        if ($rotated === false) {
            return $source;
        }

        if ($rotated !== $source) {
            imagedestroy($source);
        }

        /** @var \GdImage $rotated */
        return $rotated;
    }

    private static function flip(\GdImage $source, int $mode): \GdImage
    {
        $copy = imagecreatetruecolor(imagesx($source), imagesy($source));
        imagecopy($copy, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));
        imageflip($copy, $mode);

        return $copy;
    }

    private static function renderVariant(
        FilesystemAdapter $disk,
        ProjectMedia $media,
        \GdImage $source,
        int $width,
        int $height,
        string $variant,
        int $maxSide,
    ): string {
        $scale = min(1.0, $maxSide / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $encoded = self::encodeJpeg($source, $targetWidth, $targetHeight, $variant === 'thumb' ? 75 : 82, $width, $height);
        $path = $media->variantPath($variant);
        $disk->put($path, $encoded);

        return $path;
    }

    private static function encodeJpeg(\GdImage $source, int $targetWidth, int $targetHeight, int $quality, ?int $srcWidth = null, ?int $srcHeight = null): string
    {
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $srcWidth ?? $targetWidth, $srcHeight ?? $targetHeight);

        ob_start();
        imagejpeg($canvas, null, $quality);
        $encoded = (string) ob_get_clean();
        imagedestroy($canvas);

        return $encoded;
    }
}
