<?php

namespace Botble\Installer\Tests\Unit;

use Botble\Installer\Http\Controllers\Concerns\InteractsWithDatabaseFile;
use Botble\Installer\Services\ImportDatabaseService;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class InteractsWithDatabaseFileTest extends TestCase
{
    /**
     * Real files created per-test (in base_path) that must be cleaned up in tearDown.
     */
    protected array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (File::exists($path)) {
                File::delete($path);
            }
        }

        $this->tempFiles = [];

        Mockery::close();

        parent::tearDown();
    }

    /**
     * Create a real temp file inside base_path() so File::exists() returns true
     * for it. Track it so tearDown removes it. Returns the relative filename.
     */
    protected function createTempDatabaseFile(string $suffix): string
    {
        $filename = sprintf('test-installer-db-%s-%s.sql', uniqid(), $suffix);
        $path = base_path($filename);

        File::put($path, '-- test placeholder');

        $this->tempFiles[] = $path;

        return $filename;
    }

    /**
     * Anonymous class that exposes the protected trait method through a
     * public run() helper so tests can invoke it directly.
     */
    protected function makeTraitConsumer(): object
    {
        return new class {
            use InteractsWithDatabaseFile;

            public function run(ImportDatabaseService $service, string $fileName, ?string $explicit = null): void
            {
                $this->handleImportDatabaseFile($service, $fileName, $explicit);
            }
        };
    }

    public function test_uses_explicit_database_file_when_it_exists(): void
    {
        $filename = $this->createTempDatabaseFile('explicit');
        $expectedPath = base_path($filename);

        $service = Mockery::mock(ImportDatabaseService::class);
        $service->shouldReceive('handle')
            ->once()
            ->with($expectedPath);

        $this->makeTraitConsumer()->run($service, 'ignored-preset-id', $filename);

        $this->assertTrue(File::exists($expectedPath));
    }

    public function test_falls_back_to_convention_when_explicit_file_is_missing(): void
    {
        // Create a file matching the conventional lookup (database-{fileName}.sql).
        $conventionalSuffix = 'fallback-preset-' . uniqid();
        $conventionalFilename = sprintf('database-%s.sql', $conventionalSuffix);
        $conventionalPath = base_path($conventionalFilename);
        File::put($conventionalPath, '-- test placeholder');
        $this->tempFiles[] = $conventionalPath;

        $service = Mockery::mock(ImportDatabaseService::class);
        $service->shouldReceive('handle')
            ->once()
            ->with($conventionalPath);

        $this->makeTraitConsumer()->run(
            $service,
            $conventionalSuffix,
            'does-not-exist-' . uniqid() . '.sql'
        );

        $this->assertTrue(File::exists($conventionalPath));
    }

    public function test_ignores_explicit_parameter_when_null_and_uses_convention(): void
    {
        $conventionalSuffix = 'null-explicit-' . uniqid();
        $conventionalFilename = sprintf('database-%s.sql', $conventionalSuffix);
        $conventionalPath = base_path($conventionalFilename);
        File::put($conventionalPath, '-- test placeholder');
        $this->tempFiles[] = $conventionalPath;

        $service = Mockery::mock(ImportDatabaseService::class);
        $service->shouldReceive('handle')
            ->once()
            ->with($conventionalPath);

        $this->makeTraitConsumer()->run($service, $conventionalSuffix, null);

        $this->assertTrue(File::exists($conventionalPath));
    }

    public function test_falls_through_entire_chain_to_base_path_database_sql(): void
    {
        // Use a preset id that has NO matching files anywhere, so the lookup falls all
        // the way through to base_path('database.sql').
        //
        // Not every project ships that file (scripts built on the CMS often do not), so
        // create it when it is missing rather than asserting it as a precondition - the
        // fallback behaviour under test is the same either way.
        $baseDefault = base_path('database.sql');

        if (! File::exists($baseDefault)) {
            File::put($baseDefault, '-- test placeholder');
            $this->tempFiles[] = $baseDefault;
        }

        $handled = null;

        $service = Mockery::mock(ImportDatabaseService::class);
        $service->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (string $path) use (&$handled): void {
                $handled = $path;
            });

        $this->makeTraitConsumer()->run(
            $service,
            'no-matching-file-' . uniqid(),
            'also-nonexistent-' . uniqid() . '.sql'
        );

        $this->assertSame($baseDefault, $handled);
    }

    public function test_explicit_file_takes_precedence_over_conventional_file(): void
    {
        $explicitFilename = $this->createTempDatabaseFile('explicit-priority');
        $explicitPath = base_path($explicitFilename);

        // Also create the conventional file to verify explicit wins.
        $conventionalSuffix = 'priority-preset-' . uniqid();
        $conventionalFilename = sprintf('database-%s.sql', $conventionalSuffix);
        $conventionalPath = base_path($conventionalFilename);
        File::put($conventionalPath, '-- test placeholder');
        $this->tempFiles[] = $conventionalPath;

        $service = Mockery::mock(ImportDatabaseService::class);
        $service->shouldReceive('handle')
            ->once()
            ->with($explicitPath);

        $this->makeTraitConsumer()->run($service, $conventionalSuffix, $explicitFilename);

        $this->assertTrue(File::exists($explicitPath));
        $this->assertTrue(File::exists($conventionalPath));
    }
}
