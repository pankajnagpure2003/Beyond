<?php

namespace Botble\Media\Tests\Feature;

use Botble\ACL\Models\User;
use Botble\Media\Models\MediaFile;
use Botble\Media\Models\MediaFolder;
use Botble\Media\Repositories\Interfaces\MediaFileInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Covers the totals behind the media footer status bar.
 */
class MediaStatsTest extends TestCase
{
    use DatabaseTransactions;

    protected MediaFileInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(MediaFileInterface::class);
    }

    protected function createUser(): User
    {
        $user = new User();
        $user->forceFill([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'user-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'super_user' => 1,
        ]);
        $user->save();

        return $user->fresh();
    }

    /**
     * Keys are typed int|string, not int: this application configures User, MediaFolder and
     * MediaFile with non-incrementing string (UUID) keys, so $model->getKey() hands back a string.
     * `0` stays a valid $parentId - it is the root-folder sentinel, not an id.
     */
    protected function createFolder(int|string $userId, int|string $parentId = 0): MediaFolder
    {
        return MediaFolder::create([
            'name' => 'Test Folder ' . uniqid(),
            'slug' => 'test-folder-' . uniqid(),
            'user_id' => $userId,
            'parent_id' => $parentId,
            'color' => null,
        ]);
    }

    protected function createFile(
        int|string $folderId,
        int|string $userId,
        string $mimeType = 'image/jpeg',
        int $size = 1000
    ): MediaFile {
        return MediaFile::create([
            'name' => 'test-file-' . uniqid(),
            'folder_id' => $folderId,
            'user_id' => $userId,
            'url' => '/storage/test-' . uniqid(),
            'mime_type' => $mimeType,
            'size' => $size,
        ]);
    }

    public function test_it_counts_files_folders_size_and_types_in_a_folder(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $folder = $this->createFolder($user->id);
        $this->createFolder($user->id, $folder->id);

        $this->createFile($folder->id, $user->id, 'image/jpeg', 1000);
        $this->createFile($folder->id, $user->id, 'image/png', 2000);
        $this->createFile($folder->id, $user->id, 'video/mp4', 3000);
        $this->createFile($folder->id, $user->id, 'application/pdf', 4000);

        $stats = $this->repository->getStats($folder->id);

        $this->assertSame(1, $stats['total_folders']);
        $this->assertSame(4, $stats['total_files']);
        $this->assertSame(10000, $stats['total_size']);
        $this->assertSame(2, $stats['image_count']);
        $this->assertSame(1, $stats['video_count']);
        $this->assertSame(1, $stats['document_count']);
    }

    /**
     * The whole point of computing stats server-side: they must describe the
     * entire folder, not just the page of results currently rendered.
     */
    public function test_it_counts_every_file_regardless_of_pagination(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $folder = $this->createFolder($user->id);

        foreach (range(1, 25) as $ignored) {
            $this->createFile($folder->id, $user->id, 'image/jpeg', 100);
        }

        $paged = $this->repository->getFilesByFolderId($folder->id, [
            'paginate' => ['per_page' => 10, 'current_paged' => 1],
        ], false);

        $this->assertCount(10, $paged);

        $stats = $this->repository->getStats($folder->id);

        $this->assertSame(25, $stats['total_files']);
        $this->assertSame(2500, $stats['total_size']);
    }

    public function test_it_respects_the_mime_type_filter(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $folder = $this->createFolder($user->id);

        $this->createFile($folder->id, $user->id, 'image/jpeg', 1000);
        $this->createFile($folder->id, $user->id, 'application/pdf', 5000);

        $stats = $this->repository->getStats($folder->id, ['filter' => 'image']);

        $this->assertSame(1, $stats['total_files']);
        $this->assertSame(1000, $stats['total_size']);
        $this->assertSame(1, $stats['image_count']);
        $this->assertSame(0, $stats['document_count']);
    }

    public function test_it_respects_the_search_term(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $folder = $this->createFolder($user->id);

        $matching = $this->createFile($folder->id, $user->id);
        $matching->update(['name' => 'unique-search-target']);

        $this->createFile($folder->id, $user->id);

        $stats = $this->repository->getStats($folder->id, ['search' => 'unique-search-target']);

        $this->assertSame(1, $stats['total_files']);
    }

    public function test_it_returns_zeroes_when_nothing_is_recent_or_favorited(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $folder = $this->createFolder($user->id);
        $this->createFile($folder->id, $user->id);

        // Mirrors an empty "recent"/"favorites" view.
        $stats = $this->repository->getStats(0, ['file_ids' => [], 'folder_ids' => []]);

        $this->assertSame(0, $stats['total_folders']);
        $this->assertSame(0, $stats['total_files']);
        $this->assertSame(0, $stats['total_size']);
    }

    /**
     * Favorites span folders, so they are scoped by id alone. Regression test:
     * an id scope combined with the root scope reported 0 for every favorite
     * that lived inside a folder.
     */
    public function test_it_counts_favorites_that_live_inside_folders(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $folder = $this->createFolder($user->id);
        $favorite = $this->createFile($folder->id, $user->id, 'image/jpeg', 1500);
        $this->createFile($folder->id, $user->id);

        $stats = $this->repository->getStats(0, [
            'file_ids' => [$favorite->id],
            'folder_ids' => [$folder->id],
            'scope_to_root' => false,
        ]);

        $this->assertSame(1, $stats['total_files']);
        $this->assertSame(1500, $stats['total_size']);
        $this->assertSame(1, $stats['total_folders']);
    }

    /**
     * Trash represents files nested under a trashed folder by that folder, so
     * they must not be counted individually at the root.
     */
    public function test_it_does_not_count_files_nested_under_a_trashed_folder(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $folder = $this->createFolder($user->id);
        $this->createFile($folder->id, $user->id);
        $this->createFile($folder->id, $user->id);

        $rootFile = $this->createFile(0, $user->id);
        $rootFile->delete();

        // Deleting the folder cascades the delete to its files.
        $folder->delete();

        $stats = $this->repository->getStats(0, ['only_trashed' => true]);

        // Only the trashed root-level file is listed; the folder stands in for its two.
        $this->assertSame(1, $stats['total_files']);
        $this->assertSame(1, $stats['total_folders']);
    }

    /**
     * The invariant this feature exists to uphold: the footer must describe the
     * same set of items the list renders. Asserted against the unpaginated
     * listing so a scoping drift between the two queries fails here.
     */
    public function test_stats_agree_with_the_root_listing(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $this->createFolder($user->id);
        $this->createFile(0, $user->id, 'image/jpeg', 100);
        $this->createFile(0, $user->id, 'video/mp4', 200);

        // A file orphaned by a trashed parent folder is listed at the root.
        $trashedFolder = $this->createFolder($user->id);
        $orphan = $this->createFile($trashedFolder->id, $user->id, 'image/png', 300);
        $trashedFolder->delete();
        $orphan->restore();

        $listed = $this->repository->getFilesByFolderId(0, [], true);
        $stats = $this->repository->getStats(0);

        $this->assertSame(
            $listed->where('is_folder', 0)->count(),
            $stats['total_files'],
            'Footer file count must match the listed files'
        );
        $this->assertSame(
            $listed->where('is_folder', 1)->count(),
            $stats['total_folders'],
            'Footer folder count must match the listed folders'
        );
    }

    /**
     * Regression test: when nothing recent is a folder, the listing left the
     * folder side of the union unrestricted and rendered every root folder while
     * the footer reported zero.
     */
    public function test_stats_agree_with_the_recent_listing_when_no_folder_is_recent(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $this->createFolder($user->id);
        $this->createFolder($user->id);

        $recentFile = $this->createFile(0, $user->id);

        $listed = $this->repository->getFilesByFolderId(0, [
            'condition' => [['media_files.id', 'IN', [$recentFile->id]]],
        ], true, [
            'condition' => [['media_folders.id', 'IN', []]],
        ]);

        $stats = $this->repository->getStats(0, [
            'file_ids' => [$recentFile->id],
            'folder_ids' => [],
        ]);

        $this->assertSame($listed->where('is_folder', 1)->count(), $stats['total_folders']);
        $this->assertSame($listed->where('is_folder', 0)->count(), $stats['total_files']);
        $this->assertSame(0, $stats['total_folders']);
        $this->assertSame(1, $stats['total_files']);
    }

    public function test_stats_agree_with_the_trash_listing(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $rootFile = $this->createFile(0, $user->id);
        $rootFile->delete();

        $folder = $this->createFolder($user->id);
        $this->createFile($folder->id, $user->id);
        $folder->delete();

        $listed = $this->repository->getTrashed(0, [], true);
        $stats = $this->repository->getStats(0, ['only_trashed' => true]);

        $this->assertSame(
            $listed->where('is_folder', 0)->count(),
            $stats['total_files'],
            'Trash footer file count must match the listed files'
        );
        $this->assertSame(
            $listed->where('is_folder', 1)->count(),
            $stats['total_folders'],
            'Trash footer folder count must match the listed folders'
        );
    }

    public function test_it_excludes_trashed_files_from_the_default_scope(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $folder = $this->createFolder($user->id);

        $this->createFile($folder->id, $user->id, 'image/jpeg', 1000);
        $this->createFile($folder->id, $user->id, 'image/jpeg', 1000)->delete();

        $stats = $this->repository->getStats($folder->id);

        $this->assertSame(1, $stats['total_files']);
        $this->assertSame(1000, $stats['total_size']);

        $trashed = $this->repository->getStats($folder->id, ['only_trashed' => true]);

        $this->assertSame(1, $trashed['total_files']);
    }
}
