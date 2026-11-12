<?php

namespace Botble\Menu;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Facades\BaseHelper;
use Botble\Base\Facades\Html;
use Botble\Base\Facades\MetaBox;
use Botble\Base\Forms\FieldOptions\CoreIconFieldOption;
use Botble\Base\Forms\FieldOptions\InputFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\ColorField;
use Botble\Base\Forms\Fields\CoreIconField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Models\BaseModel;
use Botble\Base\Supports\MetadataCache;
use Botble\Base\Supports\RepositoryHelper;
use Botble\Menu\Forms\MenuNodeForm;
use Botble\Menu\Http\Requests\MenuRequest;
use Botble\Menu\Models\Menu as MenuModel;
use Botble\Menu\Models\MenuNode;
use Botble\Support\Http\Requests\Request as BaseRequest;
use Botble\Support\Services\Cache\Cache;
use Botble\Theme\Facades\Theme;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Throwable;

class Menu
{
    protected Cache $cache;

    protected array $menuOptionModels = [];

    protected Collection $data;

    protected bool $loaded = false;

    protected static array $locations = [];

    /**
     * Existing nodes and resolved reference urls for the menu currently being saved,
     * loaded once instead of one query per node. Null while no save is in progress.
     */
    protected ?array $existingMenuNodes = null;

    protected array $referenceUrls = [];

    public function __construct()
    {
        $this->cache = Cache::make(MenuModel::class);
    }

    public function hasMenu(string $slug): bool
    {
        $this->load();

        return $this->data
            ->where('slug', $slug)
            ->isNotEmpty();
    }

    public function recursiveSaveMenu(array $menuNodes, int|string $menuId, int|string $parentId): array
    {
        $isRootCall = $this->existingMenuNodes === null;

        try {
            if ($isRootCall) {
                $this->primeMenuNodeCaches($menuNodes);
            }

            foreach ($menuNodes as &$row) {
                $child = Arr::get($row, 'children', []);

                foreach ($child as $index => $item) {
                    $child[$index]['menuItem']['position'] = $index;
                }

                $hasChild = ! empty($child);

                $row['menuItem'] = $this->saveMenuNode($row['menuItem'], $menuId, $parentId, $hasChild);

                if (! empty($child) && is_array($child)) {
                    $this->recursiveSaveMenu($child, $menuId, $row['menuItem']['id']);
                }
            }

            return $menuNodes;
        } catch (Exception) {
            return [];
        } finally {
            if ($isRootCall) {
                $this->existingMenuNodes = null;
                $this->referenceUrls = [];
            }
        }
    }

    /**
     * Load everything the save needs up front.
     *
     * Without this the save runs a findOrNew plus a reference lookup for every node,
     * which is what makes a menu with several hundred links time out.
     */
    protected function primeMenuNodeCaches(array $menuNodes): void
    {
        $ids = [];
        $references = [];

        $collect = function (array $nodes) use (&$collect, &$ids, &$references): void {
            foreach ($nodes as $node) {
                $item = Arr::get($node, 'menuItem', []);

                if ($id = Arr::get($item, 'id')) {
                    $ids[] = $id;
                }

                $referenceType = Arr::get($item, 'reference_type');
                $referenceId = (int) Arr::get($item, 'reference_id');

                if ($referenceType && $referenceType !== 'custom-link' && $referenceId) {
                    $references[$referenceType][] = $referenceId;
                }

                $collect(Arr::get($node, 'children', []));
            }
        };

        $collect($menuNodes);

        $this->existingMenuNodes = $ids
            ? MenuNode::query()->whereIn('id', array_unique($ids))->get()->keyBy('id')->all()
            : [];

        $this->referenceUrls = [];

        foreach ($references as $referenceType => $referenceIds) {
            if (! class_exists($referenceType)) {
                continue;
            }

            $query = $referenceType::query()->whereIn('id', array_unique($referenceIds));

            if (method_exists($referenceType, 'slugable')) {
                $query->with('slugable');
            }

            foreach ($query->get() as $reference) {
                $this->referenceUrls[$referenceType][$reference->getKey()] = str_replace(url(''), '', $reference->url);
            }
        }
    }

    protected function saveMenuNode(
        array $menuItem,
        int|string $menuId,
        int|string $parentId,
        bool $hasChild = false
    ): array {
        $id = Arr::get($menuItem, 'id');

        /**
         * @var MenuNode $node
         */
        $node = $id && isset($this->existingMenuNodes[$id])
            ? $this->existingMenuNodes[$id]
            : MenuNode::query()->findOrNew($id);

        /**
         * Apply the payload first so we can tell whether this node actually changed.
         * On a reorder or a single title edit almost every node is untouched, and the
         * form pipeline below - a full form build plus four event and filter chains -
         * is what makes a large menu take minutes to save.
         */
        $node->fill($menuItem);
        $node->menu_id = $menuId;
        $node->parent_id = $parentId;
        $node->has_child = (int) $hasChild;
        $node = $this->getReferenceMenuNode($menuItem, $node);

        if ($node->exists && ! $node->isDirty()) {
            $menuItem['id'] = $node->getKey();

            return $menuItem;
        }

        MenuNodeForm::createFromModel($node)
            ->saving(function (MenuNodeForm $form) use ($hasChild, $parentId, $menuId, $menuItem): void {
                /**
                 * @var MenuNode $node
                 */
                $node = $form->getModel();
                $node->fill($menuItem);
                $node->menu_id = $menuId;
                $node->parent_id = $parentId;
                $node->has_child = (int) $hasChild;

                $node = $this->getReferenceMenuNode($menuItem, $node);
                $node->save();
            });

        $menuItem['id'] = $node->getKey();

        return $menuItem;
    }

    public function getReferenceMenuNode(array $item, MenuNode $menuNode): MenuNode
    {
        switch (Arr::get($item, 'reference_type')) {
            case 'custom-link':
            case '':
                $menuNode->reference_id = 0;
                $menuNode->reference_type = null;
                $menuNode->url = str_replace('&amp;', '&', Arr::get($item, 'url'));

                break;

            default:
                $menuNode->reference_id = (int) Arr::get($item, 'reference_id');
                $menuNode->reference_type = Arr::get($item, 'reference_type');

                $cachedUrl = Arr::get($this->referenceUrls, "$menuNode->reference_type.$menuNode->reference_id");

                if ($cachedUrl !== null) {
                    $menuNode->url = $cachedUrl;
                } elseif (class_exists($menuNode->reference_type)) {
                    $reference = $menuNode->reference_type::find($menuNode->reference_id);
                    if ($reference) {
                        $menuNode->url = str_replace(url(''), '', $reference->url);
                    }
                }

                break;
        }

        return $menuNode;
    }

    public function addMenuLocation(string $location, string $description): self
    {
        static::$locations[$location] = $description;

        return $this;
    }

    public function getMenuLocations(): array
    {
        Event::dispatch('cms.menu::registering-locations');

        return static::$locations;
    }

    public function removeMenuLocation(string $location): self
    {
        Arr::forget(static::$locations, $location);

        return $this;
    }

    public function clearMenuLocations(): self
    {
        static::$locations = [];

        return $this;
    }

    public function renderMenuLocation(string $location, array $attributes = []): ?string
    {
        $this->load();

        $html = '';

        foreach ($this->data as $menu) {
            if (! in_array($location, $menu->locations->pluck('location')->all())) {
                continue;
            }

            $attributes['slug'] = $menu->slug;
            $html .= $this->generateMenu($attributes);
        }

        return $html;
    }

    public function isLocationHasMenu(string $location): bool
    {
        $this->load();

        foreach ($this->data as $menu) {
            if (in_array($location, $menu->locations->pluck('location')->all())) {
                return true;
            }
        }

        return false;
    }

    public function load(bool $force = false): void
    {
        if (! $this->loaded || $force) {
            $this->data = $this->read();
            $this->loaded = true;
        }
    }

    protected function read(): Collection
    {
        $cacheEnabled = setting('cache_front_menu_enabled', true);
        $cacheKey = 'menu_all_menus_' . md5(serialize(BaseHelper::getHomepageUrl()) . app()->getLocale());

        if ($cacheEnabled && ($cached = $this->cache->get($cacheKey)) instanceof Collection) {
            return $cached;
        }

        // Every node of a menu, at every depth, belongs to the same menu_id, so `menuNodes`
        // already returns the whole tree flat. The child relation is then built in memory by
        // hydrateMenuNodeTree() instead of eager loading `menuNodes.child.*`, which only ever
        // reached two levels of model instances and left deeper levels lazy loading per node.
        $with = apply_filters('cms_menu_load_with_relations', [
            'menuNodes',
            'menuNodes.metadata',
            'menuNodes.reference',
            'menuNodes.reference.slugable',
            'locations',
        ]);

        $items = MenuModel::query()
            ->wherePublished()
            ->with($with);

        try {
            $result = RepositoryHelper::applyBeforeExecuteQuery($items, new MenuModel())->get();
        } catch (Throwable) {
            $safeWith = array_values(array_filter($with, fn ($relation) => ! str_contains($relation, '.reference')));

            $items = MenuModel::query()
                ->wherePublished()
                ->with($safeWith);

            $result = RepositoryHelper::applyBeforeExecuteQuery($items, new MenuModel())->get();
        }

        $this->hydrateMenuNodeTree($result);

        $this->preloadMenuNodeMetadata($result);

        if ($cacheEnabled) {
            $this->cache->put($cacheKey, $result);
        }

        return $result;
    }

    /**
     * Build the child relation of every menu node from the flat node collection already in
     * memory, so rendering a menu never queries again no matter how deep it is nested.
     */
    protected function hydrateMenuNodeTree(Collection $menus): void
    {
        foreach ($menus as $menu) {
            if (! $menu->relationLoaded('menuNodes')) {
                continue;
            }

            $nodesByParent = $menu->menuNodes
                ->sortBy('position')
                ->groupBy(fn (MenuNode $node) => (int) $node->parent_id);

            foreach ($menu->menuNodes as $node) {
                $children = $nodesByParent->get((int) $node->getKey());

                $node->setRelation('child', new Collection($children ? $children->all() : []));
            }
        }
    }

    protected function preloadMenuNodeMetadata(Collection $menus): void
    {
        $menuNodes = collect();

        foreach ($menus as $menu) {
            if ($menu->relationLoaded('menuNodes')) {
                $menuNodes = $menuNodes->merge($menu->menuNodes);

                foreach ($menu->menuNodes as $node) {
                    if ($node->relationLoaded('child')) {
                        $menuNodes = $menuNodes->merge($node->child);
                    }
                }
            }
        }

        if ($menuNodes->isEmpty()) {
            return;
        }

        $metadataKeys = apply_filters('menu_metadata_keys_to_preload', []);

        if (empty($metadataKeys)) {
            return;
        }

        MetadataCache::preloadForModels($menuNodes->all(), $metadataKeys);
    }

    public function generateMenu(array $args = []): ?string
    {
        $this->load();

        $view = Arr::get($args, 'view');

        $theme = Arr::get($args, 'theme', true);

        $cacheKey = 'menu_location_' . md5($this->getMenuCacheKeyPayload($args));

        // Views recurse into generateMenu() once per sub-menu, passing the nodes they already
        // hold. Caching those calls would store the whole menu model again for every branch,
        // so only the entry call - the one that still has to resolve a menu - is cached.
        $cacheEnabled = setting('cache_front_menu_enabled', true) && ! Arr::has($args, 'menu_nodes');

        $data = [];

        if ($cacheEnabled) {
            $data = $this->cache->get($cacheKey);
        }

        if (! $data) {
            $menu = Arr::get($args, 'menu');

            $slug = Arr::get($args, 'slug');
            if (! $menu && ! $slug) {
                return null;
            }

            $parentId = Arr::get($args, 'parent_id', 0);

            if (! $menu) {
                $menu = $this->data->where('slug', $slug)->first();
            }

            if (! $menu) {
                $menu = RepositoryHelper::applyBeforeExecuteQuery(
                    MenuModel::query()->where('slug', $slug),
                    new MenuModel(),
                    true
                )->first();
            }

            if (! $menu) {
                return null;
            }

            if (! Arr::has($args, 'menu_nodes')) {
                $menuNodes = $menu->menuNodes->where('parent_id', $parentId);
            } else {
                $menuNodes = Arr::get($args, 'menu_nodes', []);
            }

            if ($menuNodes instanceof Collection) {
                try {
                    $menuNodes->loadMissing(['reference', 'reference.slugable']);
                } catch (Throwable) {
                }
            }

            $menuNodes = $menuNodes->sortBy('position');

            $data = [
                'menu' => $menu,
                'menu_nodes' => $menuNodes,
            ];

            $data['options'] = Html::attributes(Arr::get($args, 'options', []));

            if ($cacheEnabled) {
                $this->cache->put($cacheKey, $data);
            }
        }

        $data = (array) $data;

        if ($theme && $view) {
            return Theme::partial($view, $data);
        }

        if ($view) {
            return view($view, $data)->render();
        }

        return view('packages/menu::partials.default', $data)->render();
    }

    /**
     * Build the cache key payload from identifiers only. Serializing $args instead would walk
     * the whole menu model with its loaded nodes, and generateMenu() runs once per sub-menu
     * level, so on a menu with many items that serialization dominates the page render time.
     */
    protected function getMenuCacheKeyPayload(array $args): string
    {
        $menu = Arr::get($args, 'menu');

        $nodeIds = [];

        if (is_iterable($menuNodes = Arr::get($args, 'menu_nodes'))) {
            foreach ($menuNodes as $node) {
                $nodeIds[] = $node instanceof BaseModel ? $node->getKey() : null;
            }
        }

        return implode('|', [
            BaseHelper::getHomepageUrl(),
            app()->getLocale(),
            Arr::get($args, 'slug') ?: ($menu instanceof MenuModel ? $menu->getKey() : ''),
            Arr::get($args, 'view', ''),
            Arr::get($args, 'theme', true) ? 1 : 0,
            Arr::get($args, 'parent_id', 0),
            serialize(Arr::get($args, 'options', [])),
            implode(',', $nodeIds),
        ]);
    }

    public function registerMenuOptions(string $model, string $name): void
    {
        $options = Menu::generateSelect([
            'model' => new $model(),
            'options' => [
                'class' => 'list-unstyled list-item',
            ],
        ]);

        echo view('packages/menu::menu-options', compact('options', 'name'));
    }

    public function generateSelect(array $args = []): ?string
    {
        /**
         * @var BaseModel|Builder $model
         */
        $model = Arr::get($args, 'model');

        $options = Html::attributes(Arr::get($args, 'options', []));

        if (! Arr::has($args, 'items')) {
            if (method_exists($model, 'children')) {
                $items = $model
                    ->where('parent_id', Arr::get($args, 'parent_id', 0))
                    ->with(['children', 'children.children'])
                    ->oldest('name');
            } else {
                $items = $model->orderBy('name');
            }

            if (Arr::get($args, 'active', true)) {
                $items = $items->where('status', BaseStatusEnum::PUBLISHED);
            }

            $items = apply_filters(BASE_FILTER_BEFORE_GET_ADMIN_LIST_ITEM, $items, $model, $model::class)->get();
        } else {
            $items = Arr::get($args, 'items', []);
        }

        if (empty($items)) {
            return null;
        }

        return view('packages/menu::partials.select', compact('items', 'model', 'options'))->render();
    }

    public function addMenuOptionModel(string $model): self
    {
        $this->menuOptionModels[] = $model;

        return $this;
    }

    public function getMenuOptionModels(): array
    {
        return $this->menuOptionModels;
    }

    public function setMenuOptionModels(array $models): self
    {
        $this->menuOptionModels = $models;

        return $this;
    }

    public function clearCacheMenuItems(): self
    {
        try {
            $nodes = MenuNode::query()
                ->whereNotNull('reference_type')
                ->whereNotNull('reference_id')
                ->where('reference_id', '>', 0)
                ->with(['reference'])
                ->get();

            foreach ($nodes as $node) {
                if (! class_exists($node->reference_type) || ! $node->reference) {
                    continue;
                }

                $node->url = rtrim(str_replace(url(''), '', $node->reference->url), '/');

                if ($node->url === rtrim(url(''), '/')) {
                    $node->url = '/';
                }

                $node->save();
            }
        } catch (Exception $exception) {
            BaseHelper::logError($exception);
        }

        return $this;
    }

    public function useMenuItemIconImage(): void
    {
        MenuNodeForm::beforeRendering(function (MenuNodeForm $form): MenuNodeForm {
            /**
             * @var MenuNode $model
             */
            $model = $form->getModel();

            $form
                ->modify(
                    'icon_font',
                    CoreIconField::class,
                    CoreIconFieldOption::make()
                )
                ->addAfter('icon_font', 'icon_image', 'mediaImage', [
                    'label' => trans('packages/menu::menu.icon_image'),
                    'attr' => [
                        'data-update' => 'icon_image',
                    ],
                    'value' => $model->icon_image ?: $model->getMetaData('icon_image', true),
                    'help_block' => [
                        'text' => trans('packages/menu::menu.icon_image_helper'),
                    ],
                    'wrapper' => [
                        'style' => 'display: block;',
                    ],
                ]);

            return $form;
        }, 124);

        MenuNodeForm::beforeSaving(function (MenuNodeForm $form): void {
            /**
             * @var MenuNode $model
             */
            $model = $form->getModel();

            $request = $form->getRequest();

            if ($request->has('data.icon_image')) {
                if ($iconImage = $request->input('data.icon_image')) {
                    MetaBox::saveMetaBoxData($model, 'icon_image', $iconImage);
                } else {
                    MetaBox::deleteMetaData($model, 'icon_image');
                }

                return;
            }

            if ($menuNodes = $request->input('menu_nodes')) {
                $menuNodes = json_decode($menuNodes, true);

                if ($menuNodes) {
                    $this->saveMenuNodeImages($menuNodes, $model);
                }
            }
        }, 170);

        add_filter('menu_nodes_item_data', function (MenuNode $data): MenuNode {
            $data->icon_image = $data->getMetaData('icon_image', true);

            return $data;
        }, 170);

        add_filter('cms_menu_load_with_relations', function (array $relations): array {
            return array_merge($relations, ['menuNodes.metadata', 'menuNodes.child.metadata']);
        }, 170);
    }

    public function saveMenuNodeImages(array $nodes, MenuNode $model): void
    {
        foreach ($nodes as $node) {
            if ($node['menuItem']['id'] == $model->getKey() && isset($node['menuItem']['icon_image'])) {
                if ($iconImage = $node['menuItem']['icon_image']) {
                    MetaBox::saveMetaBoxData($model, 'icon_image', $iconImage);
                } else {
                    MetaBox::deleteMetaData($model, 'icon_image');
                }
            }

            if (! empty($node['children'])) {
                $this->saveMenuNodeImages($node['children'], $model);
            }
        }
    }

    public function useMenuItemBadge(): void
    {
        MenuNodeForm::extend(function (MenuNodeForm $form) {
            /**
             * @var MenuNode $menuNode
             */
            $menuNode = $form->getModel();

            $form->add(
                'badge_text',
                TextField::class,
                TextFieldOption::make()
                    ->label(trans('packages/menu::menu.badge_text'))
                    ->value($menuNode->getMetaData('badge_text', true))
                    ->toArray()
            )
                ->add(
                    'badge_color',
                    ColorField::class,
                    InputFieldOption::make()
                        ->value($menuNode->getMetaData('badge_color', true) ?: '#ffffff')
                        ->label(trans('packages/menu::menu.badge_color'))
                        ->toArray()
                );

            return $form;
        });

        MenuNodeForm::beforeSaving(function (MenuNodeForm $form) {
            $model = $form->getModel();

            if ($model instanceof MenuNode) {
                $request = $form->getRequest();

                if ($menuNodes = $request->input('menu_nodes')) {
                    $menuNodes = json_decode($menuNodes, true);

                    $this->saveMenuNodeBadges($menuNodes, $model);
                }
            }

            return $form;
        }, 170);

        add_filter('core_request_rules', function (array $rules, BaseRequest $request) {
            if ($request instanceof MenuRequest) {
                $rules['badge_text'] = ['nullable', 'string'];
                $rules['badge_color'] = ['nullable', 'string'];
            }

            return $rules;
        }, 10, 2);
    }

    public function saveMenuNodeBadges(array $nodes, MenuNode $model): void
    {
        foreach ($nodes as $node) {
            if ($node['menuItem']['id'] == $model->getKey()) {
                if (isset($node['menuItem']['badge_text'])) {
                    MetaBox::saveMetaBoxData($model, 'badge_text', $node['menuItem']['badge_text']);
                }

                if (isset($node['menuItem']['badge_color'])) {
                    MetaBox::saveMetaBoxData($model, 'badge_color', $node['menuItem']['badge_color']);
                }
            }

            if (! empty($node['children'])) {
                $this->saveMenuNodeBadges($node['children'], $model);
            }
        }
    }
}
