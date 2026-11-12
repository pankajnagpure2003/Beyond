<?php

namespace Botble\Media\Tests\Unit;

use Botble\Media\Supports\ImageMemoryGuard;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ImageMemoryGuardTest extends TestCase
{
    protected string $imagePath;

    // The limit the guard is tested against. High enough to never constrain the test suite,
    // low enough that a gigapixel image cannot fit in it.
    protected string $memoryLimit = '2G';

    protected string $originalMemoryLimit;

    protected array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalMemoryLimit = (string) ini_get('memory_limit');

        // CI runs PHP with memory_limit=-1, which the guard reads as "unlimited memory available"
        // and then never refuses anything. Pin a finite limit so the estimates are meaningful.
        ini_set('memory_limit', $this->memoryLimit);

        $this->imagePath = $this->createImage(600, 400);
    }

    protected function tearDown(): void
    {
        File::delete($this->temporaryFiles);

        // The guard raises memory_limit, restore it so it does not leak into other tests.
        ini_set('memory_limit', $this->originalMemoryLimit);

        parent::tearDown();
    }

    public function test_it_reads_image_dimensions(): void
    {
        $guard = ImageMemoryGuard::make($this->imagePath);

        $this->assertSame([600, 400], $guard->getDimensions());
        $this->assertSame(600, $guard->getWidth());
        $this->assertSame(400, $guard->getHeight());
        $this->assertSame('600x400', $guard->getHumanReadableDimensions());
        $this->assertSame(0.2, $guard->getMegaPixels());
    }

    public function test_it_estimates_required_memory_from_dimensions(): void
    {
        // 600 x 400 pixels x 4 bytes x 2 concurrent copies x 1.25 overhead
        $this->assertSame(2400000, ImageMemoryGuard::make($this->imagePath)->getRequiredMemory());
    }

    public function test_it_allows_a_normal_image_without_touching_the_memory_limit(): void
    {
        $this->assertTrue(ImageMemoryGuard::make($this->imagePath)->canProcess());
        $this->assertSame($this->memoryLimit, ini_get('memory_limit'));
    }

    public function test_it_rejects_images_above_the_max_pixels_cap(): void
    {
        config(['core.media.media.max_image_pixels' => 600 * 400 - 1]);

        $this->assertTrue(ImageMemoryGuard::make($this->imagePath)->exceedsMaxPixels());
        $this->assertFalse(ImageMemoryGuard::make($this->imagePath)->canProcess());
    }

    public function test_it_allows_images_exactly_on_the_max_pixels_cap(): void
    {
        config(['core.media.media.max_image_pixels' => 600 * 400]);

        $this->assertFalse(ImageMemoryGuard::make($this->imagePath)->exceedsMaxPixels());
        $this->assertTrue(ImageMemoryGuard::make($this->imagePath)->canProcess());
    }

    public function test_the_max_pixels_cap_is_disabled_by_default(): void
    {
        $this->assertSame(0, (int) config('core.media.media.max_image_pixels'));
        $this->assertFalse(ImageMemoryGuard::make($this->fakeImageHeader(30000, 30000))->exceedsMaxPixels());
    }

    public function test_it_refuses_an_image_that_cannot_fit_within_the_memory_cap(): void
    {
        config(['core.media.media.max_memory_limit' => '512M']);

        // 30000 x 30000 needs ~9GB to decode and resize.
        $guard = ImageMemoryGuard::make($this->fakeImageHeader(30000, 30000));

        $this->assertSame(9000000000, $guard->getRequiredMemory());
        $this->assertFalse($guard->canProcess());
        $this->assertSame($this->memoryLimit, ini_get('memory_limit'));
    }

    public function test_it_raises_the_memory_limit_when_the_image_fits_under_the_cap(): void
    {
        ini_set('memory_limit', '256M');
        config(['core.media.media.max_memory_limit' => '2G']);

        // 6000 x 4000 needs ~229MB, more than what a 256M limit leaves available.
        $guard = ImageMemoryGuard::make($this->fakeImageHeader(6000, 4000));

        $this->assertGreaterThan(ImageMemoryGuard::getAvailableMemory(), $guard->getRequiredMemory());
        $this->assertTrue($guard->canProcess());
        $this->assertGreaterThan(
            ImageMemoryGuard::parseSize('256M'),
            ImageMemoryGuard::getMemoryLimit()
        );
        $this->assertLessThanOrEqual(ImageMemoryGuard::parseSize('2G'), ImageMemoryGuard::getMemoryLimit());
    }

    public function test_it_never_raises_the_memory_limit_when_the_cap_is_disabled(): void
    {
        ini_set('memory_limit', '256M');
        config(['core.media.media.max_memory_limit' => 0]);

        $this->assertFalse(ImageMemoryGuard::make($this->fakeImageHeader(6000, 4000))->canProcess());
        $this->assertSame('256M', ini_get('memory_limit'));
    }

    public function test_it_treats_an_unlimited_memory_limit_as_always_available(): void
    {
        ini_set('memory_limit', '-1');

        $this->assertSame(PHP_INT_MAX, ImageMemoryGuard::getMemoryLimit());
        $this->assertSame(PHP_INT_MAX, ImageMemoryGuard::getAvailableMemory());
        $this->assertTrue(ImageMemoryGuard::make($this->fakeImageHeader(30000, 30000))->canProcess());
    }

    public function test_it_reads_dimensions_from_raw_image_content(): void
    {
        // Cloud disks hand over the image content instead of a path.
        $guard = ImageMemoryGuard::make(File::get($this->imagePath));

        $this->assertSame([600, 400], $guard->getDimensions());
        $this->assertTrue($guard->canProcess());
    }

    public function test_it_refuses_raw_content_of_an_image_that_cannot_fit_in_memory(): void
    {
        config(['core.media.media.max_memory_limit' => '512M']);

        $guard = ImageMemoryGuard::make(File::get($this->fakeImageHeader(30000, 30000)));

        $this->assertSame([30000, 30000], $guard->getDimensions());
        $this->assertFalse($guard->canProcess());
    }

    public function test_it_ignores_sources_that_are_not_a_readable_image(): void
    {
        $this->assertTrue(ImageMemoryGuard::make('')->canProcess());
        $this->assertTrue(ImageMemoryGuard::make("/tmp/null\0byte.jpg")->canProcess());
        $this->assertTrue(ImageMemoryGuard::make(str_repeat('a', 5000))->canProcess());
        $this->assertTrue(ImageMemoryGuard::make('/path/to/a/missing/file.jpg')->canProcess());
        $this->assertNull(ImageMemoryGuard::make('/path/to/a/missing/file.jpg')->getDimensions());
        $this->assertSame(0, ImageMemoryGuard::make('/path/to/a/missing/file.jpg')->getRequiredMemory());
    }

    public function test_it_ignores_a_file_that_is_not_an_image(): void
    {
        $path = sys_get_temp_dir() . '/image-memory-guard-test.txt';
        File::put($path, 'not an image');
        $this->temporaryFiles[] = $path;

        $this->assertNull(ImageMemoryGuard::make($path)->getDimensions());
        $this->assertTrue(ImageMemoryGuard::make($path)->canProcess());
    }

    public function test_it_parses_memory_size_strings(): void
    {
        $this->assertSame(512 * 1024 * 1024, ImageMemoryGuard::parseSize('512M'));
        $this->assertSame(1024 * 1024 * 1024, ImageMemoryGuard::parseSize('1G'));
        $this->assertSame((int) (1.5 * 1024 * 1024 * 1024), ImageMemoryGuard::parseSize('1.5G'));
        $this->assertSame(1024, ImageMemoryGuard::parseSize('1K'));
        $this->assertSame(2048, ImageMemoryGuard::parseSize('2048'));
        $this->assertSame(0, ImageMemoryGuard::parseSize('0'));
        $this->assertSame(0, ImageMemoryGuard::parseSize(''));
    }

    public function test_it_resolves_dimensions_only_once(): void
    {
        $guard = ImageMemoryGuard::make($this->imagePath);

        $before = $guard->getDimensions();

        File::delete($this->imagePath);

        // Already resolved, deleting the file must not change the answer.
        $after = $guard->getDimensions();

        $this->assertSame([600, 400], $before);
        $this->assertSame([600, 400], $after);
    }

    protected function createImage(int $width, int $height): string
    {
        $path = sys_get_temp_dir() . sprintf('/image-memory-guard-%dx%d.jpg', $width, $height);

        $image = imagecreatetruecolor($width, $height);
        imagejpeg($image, $path, 70);
        imagedestroy($image);

        $this->temporaryFiles[] = $path;

        return $path;
    }

    /**
     * A valid PNG header declaring huge dimensions. getimagesize() only reads the header,
     * so the guard can be tested against gigapixel images without allocating any memory.
     */
    protected function fakeImageHeader(int $width, int $height): string
    {
        $header = "\x89PNG\r\n\x1a\n"
            . pack('N', 13) . 'IHDR' . pack('NN', $width, $height) . "\x08\x02\x00\x00\x00";

        $path = sys_get_temp_dir() . sprintf('/image-memory-guard-fake-%dx%d.png', $width, $height);

        File::put($path, $header . pack('N', crc32(substr($header, 12))));

        $this->temporaryFiles[] = $path;

        return $path;
    }
}
