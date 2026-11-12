<?php

namespace Botble\Media\Tests\Feature;

use Botble\Media\Models\MediaFile;
use Botble\Media\RvMedia;
use Botble\Media\Services\ThumbnailService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Uploading an image with huge dimensions used to kill the request with
 * "Allowed memory size exhausted", a fatal error that no try/catch can recover from.
 *
 * @see \Botble\Media\Supports\ImageMemoryGuard
 */
class OversizedImageUploadTest extends TestCase
{
    use DatabaseTransactions;

    protected RvMedia $rvMedia;

    protected string $memoryLimit;

    protected array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->rvMedia = app(RvMedia::class);
        $this->memoryLimit = (string) ini_get('memory_limit');

        // CI runs PHP with memory_limit=-1, which the guard reads as "unlimited memory available"
        // and then never refuses anything. Pin a finite limit so the estimates are meaningful.
        ini_set('memory_limit', '2G');
    }

    protected function tearDown(): void
    {
        File::delete($this->temporaryFiles);

        ini_set('memory_limit', $this->memoryLimit);

        parent::tearDown();
    }

    public function test_it_rejects_an_upload_that_would_exhaust_the_memory_limit(): void
    {
        config(['core.media.media.max_memory_limit' => '512M']);

        $countBefore = MediaFile::query()->count();

        // ~9GB would be needed to decode and resize this one.
        $result = $this->rvMedia->handleUpload($this->fakeHugeImage(30000, 30000));

        $this->assertTrue($result['error']);
        $this->assertStringContainsString('30000x30000', $result['message']);
        $this->assertStringContainsString('900 MP', $result['message']);
        $this->assertSame($countBefore, MediaFile::query()->count());
        $this->assertEmpty(Storage::allFiles());
    }

    public function test_it_rejects_an_upload_above_the_max_pixels_cap(): void
    {
        config(['core.media.media.max_image_pixels' => 1000]);

        $result = $this->rvMedia->handleUpload(UploadedFile::fake()->image('banner.jpg', 100, 100));

        $this->assertTrue($result['error']);
        $this->assertStringContainsString('100x100', $result['message']);
        $this->assertEmpty(Storage::allFiles());
    }

    public function test_it_still_uploads_images_within_the_limits(): void
    {
        $result = $this->rvMedia->handleUpload(UploadedFile::fake()->image('banner.jpg', 600, 400));

        $this->assertFalse($result['error'], $result['message'] ?? '');

        $url = $result['data']->url;

        Storage::assertExists($url);

        // The default thumbnail size must have been generated from it.
        Storage::assertExists(
            sprintf('%s-150x150.%s', File::name($url), File::extension($url))
        );
    }

    public function test_it_skips_generating_thumbnails_for_an_oversized_image(): void
    {
        $result = $this->rvMedia->handleUpload(UploadedFile::fake()->image('banner.jpg', 600, 400));

        $this->assertFalse($result['error'], $result['message'] ?? '');

        $file = MediaFile::query()->findOrFail($result['data']->id);

        config(['core.media.media.max_image_pixels' => 1000]);

        $this->assertFalse($this->rvMedia->generateThumbnails($file, null, true));
    }

    public function test_the_thumbnail_service_writes_nothing_for_an_oversized_image(): void
    {
        config(['core.media.media.max_memory_limit' => '512M']);

        $saved = app(ThumbnailService::class)
            ->setImage($this->fakeImageHeader(30000, 30000))
            ->setSize(150, 150)
            ->setDestinationPath('thumbnails')
            ->setFileName('huge.png')
            ->save();

        $this->assertFalse($saved);
        Storage::assertMissing('thumbnails/huge.png');
    }

    public function test_the_thumbnail_service_still_resizes_normal_images(): void
    {
        $saved = app(ThumbnailService::class)
            ->setImage($this->createImage(600, 400))
            ->setSize(150, 150)
            ->setDestinationPath('thumbnails')
            ->setFileName('normal.jpg')
            ->save();

        $this->assertSame('thumbnails/normal.jpg', $saved);
        Storage::assertExists('thumbnails/normal.jpg');
    }

    public function test_parse_size_still_converts_php_ini_values(): void
    {
        // getServerConfigMaxUploadFileSize() depends on this, it now delegates to the guard.
        $this->assertSame(536870912.0, $this->rvMedia->parseSize('512M'));
        $this->assertSame(2048.0, $this->rvMedia->parseSize(2048));
        $this->assertSame(0.0, $this->rvMedia->parseSize('0'));
        $this->assertGreaterThan(0, $this->rvMedia->getServerConfigMaxUploadFileSize());
    }

    protected function createImage(int $width, int $height): string
    {
        $path = sys_get_temp_dir() . sprintf('/oversized-upload-%dx%d.jpg', $width, $height);

        $image = imagecreatetruecolor($width, $height);
        imagejpeg($image, $path, 70);
        imagedestroy($image);

        $this->temporaryFiles[] = $path;

        return $path;
    }

    protected function fakeHugeImage(int $width, int $height): UploadedFile
    {
        return new UploadedFile(
            $this->fakeImageHeader($width, $height),
            'huge.png',
            'image/png',
            null,
            true
        );
    }

    /**
     * A valid PNG header declaring huge dimensions. getimagesize() only reads the header,
     * so the pipeline can be tested against gigapixel images without allocating any memory.
     */
    protected function fakeImageHeader(int $width, int $height): string
    {
        $header = "\x89PNG\r\n\x1a\n"
            . pack('N', 13) . 'IHDR' . pack('NN', $width, $height) . "\x08\x02\x00\x00\x00";

        $path = sys_get_temp_dir() . sprintf('/oversized-upload-fake-%dx%d.png', $width, $height);

        File::put($path, $header . pack('N', crc32(substr($header, 12))));

        $this->temporaryFiles[] = $path;

        return $path;
    }
}
