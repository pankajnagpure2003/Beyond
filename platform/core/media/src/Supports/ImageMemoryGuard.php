<?php

namespace Botble\Media\Supports;

/**
 * Estimates how much memory an image needs to be decoded and makes sure the process
 * can afford it before Intervention Image reads the file.
 *
 * "Allowed memory size of X bytes exhausted" is a PHP fatal error, it cannot be caught
 * by try/catch, so oversized images must be detected BEFORE they are decoded.
 */
class ImageMemoryGuard
{
    // A decoded truecolor bitmap costs 4 bytes per pixel.
    protected const BYTES_PER_PIXEL = 4;

    // Every modifier (scale, cover, crop, place watermark...) clones the bitmap,
    // so at least 2 copies live in memory at the same time.
    protected const CONCURRENT_COPIES = 2;

    // Decoder buffers + the encoded output kept in memory while saving the file.
    protected const OVERHEAD_RATIO = 1.25;

    // Never let image processing eat the very last bytes of the memory limit.
    protected const RESERVED_MEMORY = 32 * 1024 * 1024;

    protected ?array $dimensions = null;

    protected bool $resolved = false;

    final public function __construct(protected string $path)
    {
    }

    public static function make(string $path): static
    {
        return new static($path);
    }

    /**
     * @return array{0: int, 1: int}|null [width, height] or null when it's not a readable image
     */
    public function getDimensions(): ?array
    {
        if (! $this->resolved) {
            $this->resolved = true;

            // The path may actually be raw image content (cloud disks). getimagesize() only takes
            // a path and throws on a string containing null bytes, so read the content instead.
            $isRawContent = strlen($this->path) > 4096 || str_contains($this->path, "\0");

            $size = $isRawContent
                ? @getimagesizefromstring($this->path)
                : ($this->path ? @getimagesize($this->path) : false);

            if ($size && $size[0] > 0 && $size[1] > 0) {
                $this->dimensions = [$size[0], $size[1]];
            }
        }

        return $this->dimensions;
    }

    public function getWidth(): int
    {
        return $this->getDimensions()[0] ?? 0;
    }

    public function getHeight(): int
    {
        return $this->getDimensions()[1] ?? 0;
    }

    public function getRequiredMemory(): int
    {
        return (int) ceil(
            $this->getWidth()
            * $this->getHeight()
            * self::BYTES_PER_PIXEL
            * self::CONCURRENT_COPIES
            * self::OVERHEAD_RATIO
        );
    }

    /**
     * Hard cap on image dimensions, also protects against decompression bombs
     * (a small file that expands to hundreds of megapixels once decoded).
     */
    public function exceedsMaxPixels(): bool
    {
        $maxPixels = (int) config('core.media.media.max_image_pixels', 0);

        return $maxPixels > 0 && $this->getWidth() * $this->getHeight() > $maxPixels;
    }

    /**
     * Returns false when decoding this image would very likely exhaust the memory limit.
     * Raises the memory limit first when the server allows it.
     */
    public function canProcess(): bool
    {
        // Not an image we can inspect (SVG, corrupted file...), let the normal flow handle it.
        if (! $this->getDimensions()) {
            return true;
        }

        if ($this->exceedsMaxPixels()) {
            return false;
        }

        $required = $this->getRequiredMemory();

        if (static::getAvailableMemory() >= $required) {
            return true;
        }

        return $this->increaseMemoryLimit($required);
    }

    /**
     * Current memory_limit in bytes. PHP_INT_MAX when there is no limit.
     */
    public static function getMemoryLimit(): int
    {
        $limit = trim((string) @ini_get('memory_limit'));

        if ($limit === '' || $limit === '-1') {
            return PHP_INT_MAX;
        }

        return static::parseSize($limit);
    }

    public static function getAvailableMemory(): int
    {
        $limit = static::getMemoryLimit();

        if ($limit === PHP_INT_MAX) {
            return PHP_INT_MAX;
        }

        return max(0, $limit - memory_get_usage(true) - self::RESERVED_MEMORY);
    }

    /**
     * Try to raise memory_limit just enough for this image, never above the configured cap.
     */
    protected function increaseMemoryLimit(int $required): bool
    {
        $cap = static::parseSize((string) config('core.media.media.max_memory_limit', '512M'));

        if ($cap <= 0) {
            return false;
        }

        $target = memory_get_usage(true) + $required + self::RESERVED_MEMORY;

        if ($target > $cap) {
            return false;
        }

        $targetInMegabytes = (int) ceil($target / 1024 / 1024);

        @ini_set('memory_limit', $targetInMegabytes . 'M');

        return static::getAvailableMemory() >= $required;
    }

    public static function parseSize(string $size): int
    {
        $unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
        $value = (float) preg_replace('/[^0-9\.]/', '', $size);

        if ($unit) {
            $value *= pow(1024, stripos('bkmgtpezy', $unit[0]));
        }

        return (int) round($value);
    }

    public function getHumanReadableDimensions(): string
    {
        return sprintf('%dx%d', $this->getWidth(), $this->getHeight());
    }

    public function getMegaPixels(): float
    {
        return round($this->getWidth() * $this->getHeight() / 1000000, 1);
    }
}
