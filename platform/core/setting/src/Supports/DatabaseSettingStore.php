<?php

namespace Botble\Setting\Supports;

use Botble\Base\Models\BaseModel;
use Botble\Base\Supports\Helper;
use Botble\Setting\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Throwable;
use UnexpectedValueException;

class DatabaseSettingStore extends SettingStore
{
    protected bool $connectedDatabase = false;

    public function forget(string $key, bool $force = false): SettingStore
    {
        parent::forget($key);

        $segments = explode('.', $key);
        array_pop($segments);

        while ($segments) {
            $segment = implode('.', $segments);

            if ($this->get($segment)) {
                break;
            }

            $this->forget($segment);
            array_pop($segments);
        }

        return $this;
    }

    public function newQuery(): Builder
    {
        return Setting::query();
    }

    protected function write(array $data): void
    {
        // No up-front Helper::isConnectedDatabase() early return here, unlike read():
        // before install that probe is also false for an UNREACHABLE database, and
        // returning would silently drop the save. The only failure a write may
        // absorb — a settings table that is not there yet — is handled below.
        try {
            $keys = $this->newQuery()->pluck('key')->all();

            $data = Arr::dot($data);

            $updateData = Arr::only($data, $keys);
            $insertData = Arr::except($data, $keys);

            foreach ($updateData as $key => $value) {
                $this->newQuery()
                    ->where('key', $key)
                    ->update(['value' => $value]);
            }

            if ($insertData) {
                $this->newQuery()->insert($this->prepareInsertData($insertData));
            }
        } catch (QueryException $exception) {
            // Two failures land here and only one of them may be absorbed.
            //
            // The table is missing: Core::runMigrationFiles() runs
            // database_path('migrations') BEFORE the core/package loop that creates
            // it, and one of those app migrations writes theme options through here
            // on a database that has never been migrated — a new install, or under
            // multi-tenancy a store's database being provisioned (where
            // storage/installed already exists, so Helper::isConnectedDatabase()
            // would not even notice). A throw here is swallowed by Core's rescue()
            // and silently skips every migration after it, so this case degrades to
            // a no-op the way read() does.
            //
            // Anything else — a lock wait, a deadlock, a read-only transaction, a
            // connection dropped mid-request — is a write that did not happen, and
            // the caller must hear about it. Core::activateLicense() writes
            // storage/.license first and its metadata through here second, inside
            // one try/catch that turns a throw into the error the operator console
            // reports; while this catch absorbed everything, a failed metadata write
            // made activateLicense() return true, the console flashed success above
            // a "Not verified" card that said to re-activate, and the licence server
            // refused that re-activation because the slot was already consumed.
            if ($this->isMissingSettingsTable()) {
                return;
            }

            throw $exception;
        }
    }

    /**
     * The one QueryException write() and delete() may absorb. Probed on the
     * connection the model resolves — under tenancy that is the tenant database
     * being provisioned, the one Helper::isConnectedDatabase() stops probing once
     * storage/installed exists on the central install.
     */
    protected function isMissingSettingsTable(): bool
    {
        $model = new Setting();

        try {
            return ! $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable());
        } catch (Throwable) {
            // The probe itself failed, so the database is gone rather than the
            // table. That is a write that did not happen: let the original throw up.
            return false;
        }
    }

    protected function prepareInsertData(array $data): array
    {
        $dbData = [];

        foreach ($data as $key => $value) {
            $data = compact('key', 'value');
            if (BaseModel::isUsingStringId()) {
                $data['id'] = (new BaseModel())->newUniqueId();
            }

            $dbData[] = $data;
        }

        return apply_filters(SETTINGS_PREPARE_INSERT_DATA, $dbData);
    }

    protected function read(): array
    {
        if (! $this->connectedDatabase) {
            $this->connectedDatabase = Helper::isConnectedDatabase();
        }

        if (! $this->connectedDatabase) {
            return [];
        }

        try {
            return $this->parseReadData($this->newQuery()->get());
        } catch (Throwable) {
            // The settings table used to be probed with Schema::hasTable() before every
            // read, which also happened to absorb an unreachable database. That probe is
            // now skipped on installed sites, so keep the same graceful fallback here:
            // run with defaults rather than failing the whole request.
            return [];
        }
    }

    public function parseReadData(Collection|array $data): ?array
    {
        $results = [];

        foreach ($data as $row) {
            if (is_array($row)) {
                $key = $row['key'];
                $value = $row['value'];
            } elseif (is_object($row)) {
                $key = $row->key;
                $value = $row->value;
            } else {
                $msg = 'Expected array or object, got ' . gettype($row);

                throw new UnexpectedValueException($msg);
            }

            Arr::set($results, $key, $value);
        }

        return $results;
    }

    public function delete(array|string $keys = [], array $except = [], bool $force = false)
    {
        if (! $keys && ! $except) {
            return false;
        }

        if (! is_array($keys)) {
            $keys = [$keys];
        }

        foreach ($keys as $k => $v) {
            if (! $force && in_array($k, $this->guard)) {
                unset($keys[$k]);
            }
        }

        $query = $this->newQuery();

        if ($keys) {
            $query = $query->whereIn('key', $keys);
        }

        if ($except) {
            // $except, not $keys: with no keys this used to compile to
            // `whereNotIn('key', [])` — i.e. `1 = 1` — so CleanDatabaseService's
            // forceDelete(except: ['theme', 'activated_plugins', 'licensed_to', ...])
            // deleted every setting, the ones it meant to keep included.
            $query = $query->whereNotIn('key', $except);
        }

        // Same guard as write(): nothing to delete from a table that is not
        // there yet, and a caller that only wanted to remove a stale key must not
        // fatal over it — see write() for why that case is not hypothetical, and
        // for why every OTHER query failure is rethrown rather than reported as
        // "nothing deleted".
        try {
            return $query->delete();
        } catch (QueryException $exception) {
            if ($this->isMissingSettingsTable()) {
                return false;
            }

            throw $exception;
        }
    }

    public function forceDelete(array|string $keys = [], array $except = [])
    {
        return $this->delete($keys, $except, true);
    }
}
