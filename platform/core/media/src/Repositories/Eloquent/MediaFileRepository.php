<?php

namespace Botble\Media\Repositories\Eloquent;

use Botble\Base\Facades\BaseHelper;
use Botble\Base\Models\BaseModel;
use Botble\Media\Facades\RvMedia;
use Botble\Media\Models\MediaFile;
use Botble\Media\Models\MediaFolder;
use Botble\Media\Repositories\Interfaces\MediaFileInterface;
use Botble\Support\Repositories\Eloquent\RepositoriesAbstract;
use Exception;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * @since 19/08/2015 07:45 AM
 */
class MediaFileRepository extends RepositoriesAbstract implements MediaFileInterface
{
    public function createName(string $name, int|string|null $folder): string
    {
        return MediaFile::createName($name, $folder);
    }

    public function createSlug(string $name, string $extension, ?string $folderPath): string
    {
        return MediaFile::createSlug($name, $extension, $folderPath);
    }

    public function getFilesByFolderId(
        int|string|null $folderId,
        array $params = [],
        bool $withFolders = true,
        array $folderParams = []
    ) {
        $params = array_merge([
            'order_by' => [
                'name' => 'ASC',
            ],
            'select' => [
                'media_files.id as id',
                'media_files.name as name',
                'media_files.alt as alt',
                'media_files.url as url',
                'media_files.mime_type as mime_type',
                'media_files.size as size',
                'media_files.created_at as created_at',
                'media_files.updated_at as updated_at',
                'media_files.options as options',
                'media_files.folder_id as folder_id',
                'media_files.visibility as visibility',
                DB::raw('0 as is_folder'),
                DB::raw('NULL as slug'),
                DB::raw('NULL as parent_id'),
                DB::raw('NULL as color'),
            ],
            'condition' => [],
            'recent_items' => null,
            'paginate' => [
                'per_page' => null,
                'current_paged' => 1,
            ],
            'selected_file_id' => null,
            'is_popup' => false,
            'filter' => 'everything',
            'take' => null,
            'with' => [],
            'is_favorite' => false,
        ], $params);

        if ($withFolders) {
            $folderParams = array_merge([
                'condition' => [],
                'select' => [
                    'media_folders.id as id',
                    'media_folders.name as name',
                    DB::raw('NULL as url'),
                    DB::raw('NULL as mime_type'),
                    DB::raw('NULL as size'),
                    DB::raw('NULL as alt'),
                    'media_folders.created_at as created_at',
                    'media_folders.updated_at as updated_at',
                    DB::raw('NULL as options'),
                    DB::raw('NULL as folder_id'),
                    DB::raw('NULL as visibility'),
                    DB::raw('1 as is_folder'),
                    'media_folders.slug as slug',
                    'media_folders.parent_id as parent_id',
                    'media_folders.color as color',
                ],
            ], $folderParams);

            $folder = new MediaFolder();

            // Only apply special handling for favorites view when folder_id is 0
            if (isset($params['is_favorite']) && $params['is_favorite'] && empty($folderId)) {
                // In root favorites view, don't filter by parent_id
                $folder = $folder->select($folderParams['select']);
            } else {
                // Normal folder view or inside a folder in favorites view
                $folder = $folder
                    ->where('parent_id', $folderId)
                    ->select($folderParams['select']);
            }

            $this->applyConditions($folderParams['condition'], $folder);

            $this->model = $this->model
                ->union($folder);
        }

        // Only apply special handling for favorites view when folder_id is 0
        if (isset($params['is_favorite']) && $params['is_favorite'] && empty($folderId)) {
            // In root favorites view, don't filter by folder_id
            $this->model = $this->model
                ->leftJoin('media_folders', 'media_folders.id', '=', 'media_files.folder_id')
                ->whereNull('media_files.deleted_at');
        } elseif (empty($folderId)) {
            $this->model = $this->model
                ->leftJoin('media_folders', 'media_folders.id', '=', 'media_files.folder_id')
                ->where(function ($query) use ($folderId): void {
                    /**
                     * @var Builder $query
                     */
                    $query
                        ->where(function ($sub) use ($folderId): void {
                            /**
                             * @var Builder $sub
                             */
                            $sub
                                ->where('media_files.folder_id', $folderId)
                                ->whereNull('media_files.deleted_at');
                        })
                        ->orWhere(function ($sub): void {
                            /**
                             * @var Builder $sub
                             */
                            $sub
                                ->whereNull('media_files.deleted_at')
                                ->whereNotNull('media_folders.deleted_at');
                        })
                        ->orWhere(function ($sub): void {
                            /**
                             * @var Builder $sub
                             */
                            $sub
                                ->whereNull('media_files.deleted_at')
                                ->whereNull('media_folders.id');
                        });
                })
                ->withTrashed();
        } else {
            $this->model = $this->model->where('media_files.folder_id', $folderId);
        }

        if (isset($params['recent_items']) && is_array($params['recent_items']) && $params['recent_items']) {
            $this->model = $this->model->whereIn('media_files.id', Arr::get($params, 'recent_items', []));
        }

        if ($params['selected_file_id'] && $params['is_popup']) {
            $this->model = $this->model->where('media_files.id', '<>', $params['selected_file_id']);
        }

        $result = $this->getFile($params);

        if ($params['selected_file_id']) {
            if (! $params['paginate']['current_paged'] || $params['paginate']['current_paged'] == 1) {
                $currentFile = $this->originalModel;

                // Only apply special handling for favorites view when folder_id is 0
                if (isset($params['is_favorite']) && $params['is_favorite'] && empty($folderId)) {
                    // In root favorites view, don't filter by folder_id
                    $currentFile = $currentFile
                        ->where('id', $params['selected_file_id'])
                        ->select($params['select'])
                        ->first();
                } else {
                    $currentFile = $currentFile
                        ->where('media_files.folder_id', $folderId)
                        ->where('id', $params['selected_file_id'])
                        ->select($params['select'])
                        ->first();
                }
            }
        }

        if (isset($currentFile) && $params['is_popup']) {
            try {
                $result->prepend($currentFile);
            } catch (Exception $exception) {
                BaseHelper::logError($exception);
            }
        }

        return $result;
    }

    protected function getFile(array $params)
    {
        $this->applyConditions($params['condition']);

        if ($params['filter'] != 'everything') {
            $this->model = $this->model->where(function (EloquentBuilder $query) use ($params) {
                /**
                 * @var EloquentBuilder $query
                 */
                $allMimes = [];
                foreach (RvMedia::getConfig('mime_types') as $key => $value) {
                    if ($key == $params['filter']) {
                        return $query->whereIn('media_files.mime_type', $value);
                    }
                    $allMimes = array_unique(array_merge($allMimes, $value));
                }

                return $query->whereNotIn('media_files.mime_type', $allMimes);
            });
        }

        if ($params['select']) {
            $this->model = $this->model->select($params['select']);
        }

        $this->model = $this->model->orderBy('is_folder', 'desc');

        foreach ($params['order_by'] as $column => $direction) {
            if (! in_array($direction, ['asc', 'desc'])) {
                $direction = 'asc';
            }

            $this->model = $this->model->orderBy($column, $direction);
        }

        foreach ($params['with'] as $with) {
            $this->model = $this->model->with($with);
        }

        if ($params['take'] == 1) {
            $result = $this->model->first();
        } elseif ($params['take']) {
            $result = $this->model->take($params['take'])->get();
        } elseif ($params['paginate']['per_page']) {
            $paged = $params['paginate']['current_paged'] ?: 1;
            $result = $this->model
                ->skip(($paged - 1) * $params['paginate']['per_page'])
                ->limit($params['paginate']['per_page'])
                ->get();
        } else {
            $result = $this->model->get();
        }

        if (
            ! empty($params['selected_file_id'])
            && ! $params['paginate']['current_paged']
            || $params['paginate']['current_paged'] == 1
        ) {
            $currentFile = $this->originalModel
                ->where('id', $params['selected_file_id'])
                ->select($params['select'])
                ->first();
        }

        if (isset($currentFile) && $params['is_popup']) {
            try {
                /** @var BaseModel $currentFile */
                $result->prepend($currentFile);
            } catch (Exception $exception) {
                BaseHelper::logError($exception);
            }
        }

        $this->resetModel();

        return $result;
    }

    public function getTrashed(
        int|string $folderId,
        array $params = [],
        bool $withFolders = true,
        array $folderParams = []
    ): Collection {
        $params = array_merge([
            'order_by' => [
                'name' => 'ASC',
            ],
            'select' => [
                'media_files.id as id',
                'media_files.name as name',
                'media_files.url as url',
                'media_files.mime_type as mime_type',
                'media_files.size as size',
                'media_files.created_at as created_at',
                'media_files.updated_at as updated_at',
                'media_files.options as options',
                'media_files.folder_id as folder_id',
                'media_files.visibility as visibility',
                DB::raw('0 as is_folder'),
                DB::raw('NULL as slug'),
                DB::raw('NULL as parent_id'),
            ],
            'condition' => [],
            'paginate' => [
                'per_page' => null,
                'current_paged' => 1,
            ],
            'selected_file_id' => null,
            'filter' => 'everything',
            'take' => null,
            'with' => [],
        ], $params);

        $this->model = $this->model->onlyTrashed();

        if ($withFolders) {
            $folderParams = array_merge([
                'condition' => [],
                'select' => [
                    'media_folders.id as id',
                    'media_folders.name as name',
                    DB::raw('NULL as url'),
                    DB::raw('NULL as mime_type'),
                    DB::raw('NULL as size'),
                    'media_folders.created_at as created_at',
                    'media_folders.updated_at as updated_at',
                    DB::raw('NULL as options'),
                    DB::raw('NULL as folder_id'),
                    DB::raw('NULL as visibility'),
                    DB::raw('1 as is_folder'),
                    'media_folders.slug as slug',
                    'media_folders.parent_id as parent_id',
                ],
            ], $folderParams);

            $folder = new MediaFolder();

            $folder = $folder
                ->withTrashed()
                ->whereNotNull('media_folders.deleted_at')
                ->select($folderParams['select']);

            if (empty($folderId)) {
                /**
                 * @var Builder $folder
                 */
                $folder = $folder->leftJoin(
                    'media_folders as mf_parent',
                    'mf_parent.id',
                    '=',
                    'media_folders.parent_id'
                )
                    ->where(function ($query): void {
                        /**
                         * @var Builder $query
                         */
                        $query
                            ->orWhere('media_folders.parent_id', 0)
                            ->orWhereNull('mf_parent.deleted_at');
                    })
                    ->withTrashed();
            } else {
                $folder = $folder->where('media_folders.parent_id', $folderId);
            }

            $this->applyConditions($folderParams['condition'], $folder);

            $this->model = $this->model
                ->union($folder);
        }

        if (empty($folderId)) {
            $this->model = $this->model
                ->leftJoin('media_folders', 'media_folders.id', '=', 'media_files.folder_id')
                ->where(function ($query): void {
                    $query
                        // '0' as a string, never int 0: on installs configured for UUID keys
                        // this column is char(36), and MySQL compares a char column to a number by
                        // casting the STRING to a number - 'a29a4604-...' becomes 0, so `= 0`
                        // matches every nested file and the root scope silently returns the whole
                        // library. Quoting it forces a string comparison; on an int-keyed install
                        // MySQL casts the '0' literal back to 0, so both schemas behave the same.
                        ->where('media_files.folder_id', '0')
                        ->orWhereNull('media_folders.deleted_at');
                });
        } else {
            $this->model = $this->model->where('media_files.folder_id', $folderId);
        }

        return $this->getFile($params);
    }

    /**
     * Aggregate counts/size for the media footer status bar.
     *
     * Computed with dedicated aggregate queries rather than from the paginated
     * result set, so the totals stay accurate for folders larger than one page.
     *
     * Supported params: filter, search, only_trashed, file_ids, folder_ids, scope_to_root.
     *
     * The id filters are additive: "recent" restricts by id *and* by the root
     * scope, while "favorites" restricts by id only (favorites span folders) and
     * therefore passes scope_to_root = false.
     */
    public function getStats(int|string|null $folderId, array $params = []): array
    {
        $params = array_merge([
            'filter' => 'everything',
            'search' => null,
            'only_trashed' => false,
            'file_ids' => null,
            'folder_ids' => null,
            'scope_to_root' => true,
        ], $params);

        $onlyTrashed = (bool) $params['only_trashed'];

        $files = MediaFile::query();

        if ($onlyTrashed) {
            $files->onlyTrashed();
        }

        if (is_array($params['file_ids'])) {
            $files->whereIn('media_files.id', $params['file_ids']);
        }

        if (! empty($folderId)) {
            $files->where('media_files.folder_id', $folderId);
        } elseif ($params['scope_to_root']) {
            $files
                ->leftJoin('media_folders', 'media_folders.id', '=', 'media_files.folder_id')
                ->where(function (EloquentBuilder $query) use ($onlyTrashed): void {
                    // See the note on the sibling scope above: int 0 matches every UUID
                    // folder_id under MySQL's string-to-number coercion.
                    $query->where('media_files.folder_id', '0');

                    if ($onlyTrashed) {
                        // Mirror getTrashed(): files nested under a trashed folder are
                        // represented by that folder, so they are not listed here.
                        $query->orWhereNull('media_folders.deleted_at');
                    } else {
                        // Mirror getFilesByFolderId(): include files orphaned by a
                        // deleted or missing parent folder.
                        $query
                            ->orWhereNotNull('media_folders.deleted_at')
                            ->orWhereNull('media_folders.id');
                    }
                });
        }

        if ($params['search']) {
            $files->where('media_files.name', 'LIKE', '%' . $params['search'] . '%');
        }

        $mimeTypes = RvMedia::getConfig('mime_types') ?: [];

        // Mirrors getFile() exactly, including the null case: an unrecognised filter
        // (or null) means "everything that is in no known bucket", not "no filter".
        if ($params['filter'] != 'everything') {
            if (isset($mimeTypes[$params['filter']])) {
                $files->whereIn('media_files.mime_type', $mimeTypes[$params['filter']]);
            } else {
                $files->whereNotIn(
                    'media_files.mime_type',
                    $mimeTypes ? array_unique(array_merge(...array_values($mimeTypes))) : []
                );
            }
        }

        // One grouped query gives us every number the footer needs; the number of
        // distinct mime types in a folder is always small.
        $groupedByMimeType = $files
            ->select([
                'media_files.mime_type as mime_type',
                DB::raw('COUNT(*) as total_files'),
                DB::raw('COALESCE(SUM(media_files.size), 0) as total_size'),
            ])
            ->groupBy('media_files.mime_type')
            ->get();

        $countByType = fn (string $type): int => (int) $groupedByMimeType
            ->whereIn('mime_type', $mimeTypes[$type] ?? [])
            ->sum('total_files');

        $folders = MediaFolder::query();

        if ($onlyTrashed) {
            $folders->onlyTrashed();
        }

        if (is_array($params['folder_ids'])) {
            $folders->whereIn('media_folders.id', $params['folder_ids']);
        }

        if (! empty($folderId)) {
            $folders->where('media_folders.parent_id', $folderId);
        } elseif ($params['scope_to_root']) {
            if ($onlyTrashed) {
                // Mirror getTrashed(): a trashed folder is listed at the root unless
                // its parent is trashed too (the parent represents it instead).
                $folders
                    ->leftJoin('media_folders as mf_parent', 'mf_parent.id', '=', 'media_folders.parent_id')
                    ->where(function (EloquentBuilder $query): void {
                        $query
                            ->where('media_folders.parent_id', 0)
                            ->orWhereNull('mf_parent.deleted_at');
                    });
            } else {
                $folders->where('media_folders.parent_id', 0);
            }
        }

        if ($params['search']) {
            $folders->where('media_folders.name', 'LIKE', '%' . $params['search'] . '%');
        }

        $totalSize = (int) $groupedByMimeType->sum('total_size');

        return [
            'total_folders' => $folders->count(),
            'total_files' => (int) $groupedByMimeType->sum('total_files'),
            'total_size' => $totalSize,
            'human_total_size' => BaseHelper::humanFilesize($totalSize),
            'image_count' => $countByType('image'),
            'video_count' => $countByType('video'),
            'document_count' => $countByType('document'),
        ];
    }

    public function emptyTrash(): bool
    {
        $this->model->onlyTrashed()->each(fn (MediaFile $file) => $file->forceDelete());

        return true;
    }
}
