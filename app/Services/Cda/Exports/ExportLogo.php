<?php

namespace App\Services\Cda\Exports;

use App\Models\BrandingSetting;
use finfo;
use GdImage;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The cooperative's logo for a CDA PDF export (gap round B4), from Settings ›
 * Branding's file on the public disk, read when the PDF is rendered (never
 * for Excel). Only the PDF renderer asks for it.
 *
 * The file is used only when:
 * - its path stays on the disk (no `..`, `://`, leading `/` or NUL);
 * - it's at most MAX_BYTES, and its real type (read from its contents, never
 *   its name) is PNG, JPEG or GIF;
 * - it decodes as an image of at most MAX_SOURCE_SIDE pixels a side, and
 *   decoding it fits in the memory PHP has left (fitsInMemory()). PHP's
 *   bundled GD allocates through PHP's memory manager, so running out is a
 *   fatal error no catch can stop: the estimate comes first. The JPEG
 *   library's own buffers sit outside memory_limit, which the side limit
 *   keeps small.
 *
 * With GD it is redrawn at most MAX_HEIGHT pixels tall (the aspect kept,
 * never enlarged), flattened onto white and encoded as an opaque PNG. A small
 * opaque image keeps dompdf off its slow, memory-hungry alpha path, which a
 * large transparent logo sends it down until the export fails. Without GD
 * only an image of at most NO_GD_MAX_SIDE pixels a side is used, as it is.
 *
 * Anything else, and any error on the way (the branding read included),
 * gives null, silently: the header then shows the name alone.
 * The result is a data URI, never a URL, so dompdf fetches nothing.
 */
class ExportLogo
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_SOURCE_SIDE = 4000;

    public const MAX_HEIGHT = 600;

    public const NO_GD_MAX_SIDE = 1500;

    private const TYPES = ['image/png', 'image/jpeg', 'image/gif'];

    /** Room kept free besides the decoded pixels, in bytes. */
    private const HEADROOM = 8 * 1024 * 1024;

    public function dataUri(): ?string
    {
        try {
            return $this->build();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The bytes a pixel decoding takes, peak: a truecolour PNG holds GD's
     * 4-byte pixels and a raw copy of its rows while it reads (about 8), a
     * palette or greyscale PNG about 2, a GIF 1, a JPEG GD's 4.
     */
    public static function bytesPerPixel(string $bytes, string $type): int
    {
        if ($type === 'image/gif') {
            return 1;
        }

        if ($type !== 'image/png') {
            return 4;
        }

        $colourType = strlen($bytes) > 25 ? ord($bytes[25]) : 6;

        return in_array($colourType, [0, 3, 4], true) ? 2 : 8;
    }

    private function build(): ?string
    {
        $bytes = $this->read();

        if ($bytes === null) {
            return null;
        }

        $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (! in_array($type, self::TYPES, true)) {
            return null;
        }

        try {
            $size = getimagesizefromstring($bytes);
        } catch (Throwable) {
            return null;
        }

        [$width, $height] = is_array($size) ? [(int) $size[0], (int) $size[1]] : [0, 0];

        if ($width < 1 || $height < 1 || $width > self::MAX_SOURCE_SIDE || $height > self::MAX_SOURCE_SIDE) {
            return null;
        }

        if (! function_exists('imagecreatefromstring')) {
            return $width <= self::NO_GD_MAX_SIDE && $height <= self::NO_GD_MAX_SIDE
                ? 'data:'.$type.';base64,'.base64_encode($bytes)
                : null;
        }

        $png = $this->flattened($bytes, $type, $width, $height);

        return $png === null ? null : 'data:image/png;base64,'.base64_encode($png);
    }

    /**
     * Whether decoding an image of this size, drawing its copy (4 bytes a
     * pixel) and the PNG writer's buffer (about 3 bytes a pixel of the copy),
     * with a quarter more and HEADROOM to spare, fit in the memory PHP has
     * left under `memory_limit` ("-1" is no limit).
     */
    public static function fitsInMemory(int $width, int $height, int $bytesPerPixel, string $memoryLimit, int $used): bool
    {
        $limit = self::bytes($memoryLimit);

        if ($limit < 0) {
            return true;
        }

        [$copyWidth, $copyHeight] = self::copySize($width, $height);
        $needed = (int) ceil(($width * $height * $bytesPerPixel + $copyWidth * $copyHeight * (4 + 3)) * 1.25);

        return $used + $needed + self::HEADROOM <= $limit;
    }

    private function read(): ?string
    {
        $path = BrandingSetting::query()->orderBy('id')->value('logo_path');

        if (! is_string($path) || $path === '' || str_contains($path, '..') || str_contains($path, '://') || str_starts_with($path, '/') || str_contains($path, "\0")) {
            return null;
        }

        try {
            $disk = Storage::disk('public');

            if (! $disk->exists($path) || $disk->size($path) > self::MAX_BYTES) {
                return null;
            }

            $bytes = $disk->get($path);
        } catch (Throwable) {
            return null;
        }

        return is_string($bytes) && $bytes !== '' && strlen($bytes) <= self::MAX_BYTES ? $bytes : null;
    }

    /** The image at most MAX_HEIGHT tall on white, as PNG bytes; null when GD can't read it or it wouldn't fit in memory. */
    private function flattened(string $bytes, string $type, int $width, int $height): ?string
    {
        if (! self::fitsInMemory($width, $height, self::bytesPerPixel($bytes, $type), (string) ini_get('memory_limit'), memory_get_usage(true))) {
            return null;
        }

        $level = ob_get_level();

        try {
            $source = imagecreatefromstring($bytes);

            if (! $source instanceof GdImage) {
                return null;
            }

            [$copyWidth, $copyHeight] = self::copySize($width, $height);
            $copy = imagecreatetruecolor($copyWidth, $copyHeight);
            imagefilledrectangle($copy, 0, 0, $copyWidth - 1, $copyHeight - 1, (int) imagecolorallocate($copy, 255, 255, 255));
            imagealphablending($copy, true);
            imagecopyresampled($copy, $source, 0, 0, 0, 0, $copyWidth, $copyHeight, imagesx($source), imagesy($source));
            imagedestroy($source);
            unset($source);
            imagesavealpha($copy, false);

            ob_start();
            $written = imagepng($copy);
            $png = (string) ob_get_clean();
        } catch (Throwable) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            return null;
        }

        return $written && $png !== '' ? $png : null;
    }

    /** @return array{0: int, 1: int} the copy's size: at most MAX_HEIGHT tall, never enlarged */
    private static function copySize(int $width, int $height): array
    {
        if ($height <= self::MAX_HEIGHT) {
            return [$width, $height];
        }

        return [max(1, (int) round($width * self::MAX_HEIGHT / $height)), self::MAX_HEIGHT];
    }

    /** "128M", "1G", "512K" or a byte count; -1 for no limit. */
    private static function bytes(string $limit): int
    {
        $limit = trim($limit);

        if ($limit === '' || $limit === '-1') {
            return -1;
        }

        $number = (int) $limit;

        return match (strtoupper(substr($limit, -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
