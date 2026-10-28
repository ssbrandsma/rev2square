<?php

namespace App\Services\Profile;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Avatar uploads: bounded validation, resized re-encode as JPEG.
 * Re-encoding drops EXIF/GPS metadata; animated GIFs keep only their
 * first frame; transparency flattens onto white.
 */
class AvatarService
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public const MAX_DIMENSION = 4000;

    public const TARGET_SIZE = 256;

    public function store(User $user, UploadedFile $file): string
    {
        if ($file->getSize() === false || $file->getSize() > self::MAX_BYTES) {
            throw new InvalidArgumentException('Avatar must be at most 2 MB.');
        }

        $info = @getimagesize($file->getRealPath());

        if ($info === false || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw new InvalidArgumentException('Avatar must be a JPEG, PNG, WebP, or GIF image.');
        }

        [$width, $height] = $info;

        if ($width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw new InvalidArgumentException('Avatar dimensions are out of bounds.');
        }

        $source = match ($info['mime']) {
            'image/jpeg' => imagecreatefromjpeg($file->getRealPath()),
            'image/png' => imagecreatefrompng($file->getRealPath()),
            'image/webp' => imagecreatefromwebp($file->getRealPath()),
            default => imagecreatefromgif($file->getRealPath()),
        };

        if ($source === false) {
            throw new InvalidArgumentException('Avatar could not be read.');
        }

        try {
            $scale = min(1.0, self::TARGET_SIZE / max($width, $height));
            $target = imagecreatetruecolor(
                max(1, (int) round($width * $scale)),
                max(1, (int) round($height * $scale))
            );

            if ($target === false) {
                throw new InvalidArgumentException('Avatar could not be processed.');
            }

            // Flatten transparency onto white before the JPEG re-encode.
            $white = imagecolorallocate($target, 255, 255, 255);
            imagefill($target, 0, 0, $white);
            imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);

            ob_start();
            $encoded = imagejpeg($target, null, 82);
            $bytes = (string) ob_get_clean();
            imagedestroy($target);

            if (! $encoded || $bytes === '') {
                throw new InvalidArgumentException('Avatar could not be processed.');
            }

            $path = 'avatars/'.$user->id.'-'.Str::random(12).'.jpg';
            Storage::disk('local')->put($path, $bytes);

            if ($user->avatar_path !== null && $user->avatar_path !== $path) {
                Storage::disk('local')->delete($user->avatar_path);
            }

            $user->forceFill(['avatar_path' => $path])->save();

            return $path;
        } finally {
            imagedestroy($source);
        }
    }

    public function delete(User $user): void
    {
        if ($user->avatar_path !== null) {
            Storage::disk('local')->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
        }
    }
}
