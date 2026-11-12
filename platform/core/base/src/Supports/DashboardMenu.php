<?php

namespace Botble\Base\Supports;

use Botble\ACL\Contracts\HasPermissions;
use Botble\Base\Events\DashboardMenuRetrieved;
use Botble\Base\Events\DashboardMenuRetrieving;
use Botble\Base\Facades\BaseHelper;
use Botble\Support\Services\Cache\Cache;
use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Tappable;
use RuntimeException;
use Throwable;

class DashboardMenu
{
    use Conditionable;
    use Tappable;

    protected array $links = [];

    protected array $removedItems = [];

    protected string $groupId = 'admin';

    protected array $beforeRetrieving = [];

    protected array $afterRetrieved = [];

    protected bool $cacheEnabled;

    protected Cache $cache;

    public function __construct(
        protected Application $app,
        protected Request $request
    ) {
        $this->cacheEnabled = (bool) setting('cache_admin_menu_enable', false);
        $this->cache = Cache::make(static::class);
    }

    public function make(): static
    {
        return $this;
    }

    public function setGroupId(string $id): static
    {
        $this->groupId = $id;

        return $this;
    }

    public function for(string $id): static
    {
        return $this->setGroupId($id);
    }

    public function default(): static
    {
        return $this->for('admin');
    }

    public function group(string $id, Closure $callback): static
    {
        $this->for($id);

        $callback($this);

        $this->default();

        return $this;
    }

    public function getGroupId(): string
    {
        return $this->groupId;
    }

    public function registerItem(Arrayable|array $options): static
    {
        if ($this->hasCache()) {
            return $this;
        }

        if ($options instanceof Arrayable) {
            $options = $options->toArray();
        }

        if (isset($options['children'])) {
            unset($options['children']);
        }

        $defaultOptions = [
            'id' => '',
            'priority' => 99,
            'parent_id' => null,
            'name' => '',
            'icon' => null,
            'url' => '',
            'route' => '',
            'permissions' => [],
            'children' => [],
            'active' => false,
        ];

        $options = [...$defaultOptions, ...$options];

        if (! $options['url'] && $options['route']) {
            $options['url'] = fn () => route($options['route']);

            if (! $options['permissions'] && $options['permissions'] !== false) {
                $options['permissions'] = [$options['route']];
            }
        }

        $id = $options['id'];

        throw_if(
            ! $id && $this->isLocal(),
            new RuntimeException(sprintf('Menu id not specified on class %s', $this->getPreviousCalledClass()))
        );

        throw_if(
            isset($this->links[$this->groupId][$id])
            && empty($this->links[$this->groupId][$id]['name'])
            && $this->isLocal(),
            new RuntimeException(sprintf('Menu id already exists: %s on class %s', $id, $this->getPreviousCalledClass()))
        );

        $this->links[$this->groupId][$id] = $options;

        return $this;
    }

    public function removeItem(string|array $id): static
    {
        if (is_array($id)) {
            foreach ($id as $item) {
                $this->removeItem($item);
            }

            return $this;
        }

        if (($args = func_get_args()) && count($args) > 1) {
            return $this->removeItem($args);
        }

        $this->removedItems[$this->groupId][] = $id;

        return $this;
    }

    public function hasItem(string $id): bool
    {
        return isset($this->links[$this->groupId][$id]);
    }

    public function getAll(?string $id = null): Collection
    {
        if ($id !== null) {
            $this->setGroupId($id);
        }

        DashboardMenuRetrieving::dispatch($this);

        do_action('render_dashboard_menu', $this, $id);

        $value = function () {
            $this->dispatchBeforeRetrieving();

            $items = $this->getItemsByGroup();

            return tap(
                $this->ensureCollection(apply_filters('dashboard_menu', $items, $this), $items),
                function (Collection $menu): void {
                    $this->dispatchAfterRetrieved($menu);
                }
            );
        };

        if ($this->cacheEnabled) {
            $items = $this->cache->remember(
                $this->cacheKey(),
                Carbon::now()->addHours(3),
                function () use ($value) {
                    return $this->sanitizeItemsForCache(value($value));
                }
            );

            // The cache may return null when the entry expires or is flushed between
            // the existence check and the read, so rebuild the menu instead of failing.
            if (! $items instanceof Collection) {
                $items = value($value);
            }
        } else {
            $items = value($value);
        }

        return tap($this->applyActive($items), function (Collection $items): void {
            DashboardMenuRetrieved::dispatch($this, $items);

            do_action('rendered_dashboard_menu', $this, $items);

            $this->default();
        });
    }

    public function getItemById(string $itemId): ?array
    {
        if (! $this->hasItem($itemId)) {
            return null;
        }

        return tap(
            $this->links[$this->groupId][$itemId],
            fn () => $this->default()
        );
    }

    public function getItemsByParentId(string $parentId): ?Collection
    {
        return collect($this->links[$this->groupId] ?? [])
            ->filter(fn ($item) => $item['parent_id'] === $parentId)
            ->tap(fn () => $this->default());
    }

    public function beforeRetrieving(Closure $callback): static
    {
        $this->beforeRetrieving[$this->groupId][] = $callback;

        $this->default();

        return $this;
    }

    protected function dispatchBeforeRetrieving(): void
    {
        if (empty($this->beforeRetrieving[$this->groupId])) {
            return;
        }

        foreach ($this->beforeRetrieving[$this->groupId] as $callback) {
            call_user_func($callback, $this);
        }
    }

    public function afterRetrieved(Closure $callback): static
    {
        $this->afterRetrieved[$this->groupId][] = $callback;

        $this->default();

        return $this;
    }

    public function clearCachesForCurrentUser(): void
    {
        $this->cache->forget($this->cacheKey());

        $this->default();
    }

    public function clearCaches(): void
    {
        $this->cache->flush();
    }

    protected function dispatchAfterRetrieved(Collection $menu): void
    {
        if (empty($this->afterRetrieved[$this->groupId])) {
            return;
        }

        foreach ($this->afterRetrieved[$this->groupId] as $callback) {
            call_user_func($callback, $this, $menu);
        }
    }

    protected function parseUrl(string|callable|Closure|null $link): string
    {
        if (empty($link)) {
            return '';
        }

        if (is_string($link)) {
            return $link;
        }

        return call_user_func($link);
    }

    protected function isLocal(): bool
    {
        return ! $this->app->runningInConsole() && $this->app->isLocal();
    }

    protected function getPreviousCalledClass(): string
    {
        return isset(debug_backtrace()[1])
            ? debug_backtrace()[1]['class'] . '@' . debug_backtrace()[1]['function']
            : '[undefined]';
    }

    protected function cacheKey(): string
    {
        $userType = 'undefined';
        $userKey = 'guest';
        $locale = $this->app->getLocale();

        if ($user = $this->request->user()) {
            $userType = $user::class;
            $userKey = $user->getKey();
        }

        return sprintf('dashboard_menu:%s:%s:%s:%s', $this->groupId, $userType, $userKey, $locale);
    }

    public function hasCache(): bool
    {
        if (! $this->cacheEnabled) {
            return false;
        }

        return $this->cache->has($this->cacheKey());
    }

    protected function getItemsByGroup(): Collection
    {
        $groupedItems = $this->getGroupedItemsByGroup();

        return $this->getMappedItems($groupedItems[''] ?? collect(), $groupedItems);
    }

    /**
     * Guard against a `dashboard_menu` filter listener that forgets to return the
     * menu (or returns something else). Without this, a single third-party plugin
     * breaks the whole admin layout with a TypeError.
     */
    protected function ensureCollection(mixed $items, Collection $fallback): Collection
    {
        if ($items instanceof Collection) {
            return $items;
        }

        if (is_array($items)) {
            return collect($items);
        }

        if ($items instanceof Arrayable) {
            return collect($items->toArray());
        }

        return $fallback;
    }

    /**
     * Resolve every Closure inside the menu tree so the Collection is safe to
     * serialize to the file/redis cache store. Required because plugins may add
     * items via apply_filters('dashboard_menu', ...) or registerItem([...]) that
     * still carry Closures in fields other than url/name.
     */
    protected function sanitizeItemsForCache(Collection $items): Collection
    {
        return $items->map(fn ($item) => $this->sanitizeValue($item));
    }

    protected function sanitizeValue(mixed $value): mixed
    {
        if ($value instanceof Closure) {
            try {
                return $this->sanitizeValue(call_user_func($value));
            } catch (Throwable) {
                return null;
            }
        }

        if ($value instanceof Collection) {
            return $value->map(fn ($item) => $this->sanitizeValue($item));
        }

        if (is_array($value)) {
            foreach ($value as $key => $nested) {
                $value[$key] = $this->sanitizeValue($nested);
            }

            return $value;
        }

        return $value;
    }

    protected function getGroupedItemsByGroup(): Collection
    {
        $removedItems = $this->removedItems[$this->groupId] ?? [];

        $items = collect($this->links[$this->groupId] ?? [])
            ->values()
            ->reject(
                fn ($link) =>
                    isset($link['id'])
                    && (
                        in_array($link['id'], $removedItems)
                        || in_array($link['parent_id'], $removedItems)
                    )
            )
            ->filter(function ($link) {
                $user = $this->request->user();

                if (! empty($link['permissions'])
                    && $user instanceof HasPermissions
                    && ! $user->hasAnyPermission($link['permissions'])) {
                    return false;
                }

                return true;
            });

        $existsIds = $items->pluck('id')->all();

        return $items
            ->mapWithKeys(function ($item) use ($existsIds): array {
                $item['url'] = $this->parseUrl($item['url'] ?? null);

                $item['name'] = $item['name'] instanceof Closure ? call_user_func($item['name']) : $item['name'];

                if (! empty($item['parent_id'])) {
                    if (! in_array($item['parent_id'], $existsIds)) {
                        $item['parent_id'] = null;
                    }

                    if ($item['parent_id'] === 'cms-core-platform-administration') {
                        $item['parent_id'] = 'cms-core-system';
                    }
                }

                return [$item['id'] => $item];
            })
            ->sortBy('priority')
            ->groupBy('parent_id');
    }

    protected function getMappedItems(Collection $items, Collection $groupedItems): Collection
    {
        return $items
            ->reject(function ($item) use ($groupedItems): bool {
                return (
                    empty($item['url']) || $item['url'] === '#' || Str::startsWith($item['url'], 'javascript:void(0)')
                ) && ! $groupedItems->get($item['id']);
            })
            ->mapWithKeys(function ($item) use ($groupedItems) {
                $groupedItem = $groupedItems->get($item['id']);

                if ($groupedItem instanceof Collection && $groupedItem->isNotEmpty()) {
                    $item['children'] = $this->getMappedItems(
                        $groupedItem,
                        $groupedItems
                    );
                } else {
                    $item['children'] = collect();
                }

                return [$item['id'] => $item];
            });
    }

    protected function applyActive(Collection $menu): Collection
    {
        $currentUrl = $this->request->fullUrl();
        $adminRoot = url(BaseHelper::getAdminPrefix());

        // First pass: find the single most-specific matching item across the whole
        // menu tree (the longest matching URL). Selecting the longest match - instead
        // of the first substring match - keeps a nested sibling (e.g. /admin/plugin/history)
        // highlighted over the plugin root item (/admin/plugin) whose URL is a prefix of it.
        $bestLength = 0;

        foreach ($menu as $item) {
            $this->findLongestActiveMatch($item, $currentUrl, $adminRoot, $bestLength);
        }

        if ($bestLength === 0) {
            return $menu;
        }

        // Second pass: mark the first item that matches at the longest length active,
        // together with its ancestors, and stop once it is found.
        $matched = false;

        foreach ($menu as $key => $item) {
            $menu[$key] = $this->applyActiveRecursive($item, $currentUrl, $adminRoot, $bestLength, $matched);

            if ($matched) {
                break;
            }
        }

        return $menu;
    }

    protected function isMenuItemMatched(string $currentUrl, string $url, string $adminRoot): bool
    {
        return $currentUrl === $url
            || (Str::contains($currentUrl, $url) && $url !== $adminRoot);
    }

    protected function findLongestActiveMatch(array $item, string $currentUrl, string $adminRoot, int &$bestLength): void
    {
        if ($this->isMenuItemMatched($currentUrl, $item['url'], $adminRoot)) {
            $bestLength = max($bestLength, Str::length($item['url']));
        }

        foreach ($item['children'] as $child) {
            $this->findLongestActiveMatch((array) $child, $currentUrl, $adminRoot, $bestLength);
        }
    }

    protected function applyActiveRecursive(array $item, string $currentUrl, string $adminRoot, int $bestLength, bool &$matched): array
    {
        $item['active'] = false;

        if (! $item['children']->isEmpty()) {
            $children = $item['children']->toArray();

            foreach ($children as &$child) {
                $child = $this->applyActiveRecursive($child, $currentUrl, $adminRoot, $bestLength, $matched);

                if ($child['active']) {
                    $item['active'] = true;

                    break;
                }
            }

            unset($child);

            $item['children'] = collect($children);

            if ($item['active']) {
                return $item;
            }
        }

        if (
            ! $matched
            && $this->isMenuItemMatched($currentUrl, $item['url'], $adminRoot)
            && Str::length($item['url']) === $bestLength
        ) {
            $item['active'] = true;
            $matched = true;
        }

        return $item;
    }
}
