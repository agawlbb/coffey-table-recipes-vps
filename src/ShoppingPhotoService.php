<?php
declare(strict_types=1);

/** Private household attachments; image bytes never enter list snapshots. */
final class ShoppingPhotoService
{
    public function __construct(private string $directory) {}

    private function path(string $household, string $photo): string
    {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $household)
            || !preg_match('/^[a-f0-9]{64}$/D', $photo)) {
            throw new HttpError(422, 'invalid_photo', 'Invalid shopping photo.');
        }
        return $this->directory.'/'.$household.'/'.$photo.'.jpg';
    }

    public function exists(string $household, string $photo): bool
    {
        $path = $this->path($household, $photo);
        return is_file($path) && !is_link($path);
    }

    public function upload(string $household, array $body): array
    {
        $encoded = $body['image_base64'] ?? null;
        if (!is_string($encoded) || strlen($encoded) > 7000000) {
            throw new HttpError(422, 'invalid_photo', 'Choose an image of at most 5 MB.');
        }
        $bytes = base64_decode($encoded, true);
        $info = $bytes === false ? false : @getimagesizefromstring($bytes);
        if (!$info || strlen($bytes) > 5 * 1024 * 1024 || $info[0] * $info[1] > 24000000
            || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new HttpError(422, 'invalid_photo', 'Choose a JPEG, PNG or WebP image of at most 5 MB and 24 megapixels.');
        }
        $source = @imagecreatefromstring($bytes);
        if (!$source) throw new HttpError(422, 'invalid_photo', 'This image could not be opened.');
        if ($info['mime'] === 'image/jpeg') {
            $stream = fopen('php://temp', 'w+b');
            try {
                fwrite($stream, $bytes); rewind($stream);
                $exif = @exif_read_data($stream);
                $orientation = $exif['Orientation'] ?? 1;
                if (in_array($orientation, [2, 4, 5, 7], true)) imageflip($source, IMG_FLIP_HORIZONTAL);
                $angle = match ($orientation) { 3, 4 => 180, 6, 7 => -90, 5, 8 => 90, default => 0 };
                if ($angle !== 0) {
                    $rotated = imagerotate($source, $angle, 0);
                    imagedestroy($source); $source = $rotated;
                }
                $info[0] = imagesx($source); $info[1] = imagesy($source);
            } finally { fclose($stream); }
        }
        // Re-encode to discard metadata and bound memory/network costs for viewing.
        $scale = min(1, 1600 / max($info[0], $info[1]));
        $image = imagecreatetruecolor(max(1, (int)round($info[0] * $scale)), max(1, (int)round($info[1] * $scale)));
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagecopyresampled($image, $source, 0, 0, 0, 0, imagesx($image), imagesy($image), $info[0], $info[1]);
        ob_start(); imagejpeg($image, null, 85); $normalized = ob_get_clean();
        imagedestroy($source); imagedestroy($image);
        if (!is_string($normalized) || $normalized === '') throw new RuntimeException('Image conversion failed.');
        $photo = hash('sha256', $normalized);
        $path = $this->path($household, $photo);
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            throw new RuntimeException('Photo storage unavailable.');
        }
        if (!is_file($path)) {
            $temporary = tempnam(dirname($path), '.upload-');
            try {
                if ($temporary === false || file_put_contents($temporary, $normalized) !== strlen($normalized)
                    || !rename($temporary, $path)) throw new RuntimeException('Photo storage unavailable.');
            } finally { if ($temporary !== false && is_file($temporary)) unlink($temporary); }
        }
        return ['photo' => $photo];
    }

    public function read(string $household, string $photo): array
    {
        if (!$this->exists($household, $photo)) throw new HttpError(404, 'photo_not_found', 'Photo unavailable.');
        $bytes = file_get_contents($this->path($household, $photo));
        if ($bytes === false) throw new RuntimeException('Photo storage unavailable.');
        return ['image_base64' => base64_encode($bytes)];
    }
}
