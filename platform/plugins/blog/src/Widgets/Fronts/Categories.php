<?php

namespace Botble\Blog\Widgets\Fronts;

use Botble\Base\Forms\FieldOptions\NameFieldOption;
use Botble\Base\Forms\FieldOptions\SelectFieldOption;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Blog\Models\Category;
use Botble\Blog\Models\Post;
use Botble\Widget\AbstractWidget;
use Botble\Widget\Forms\WidgetForm;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class Categories extends AbstractWidget
{
    public function __construct()
    {
        parent::__construct([
            'name' => trans('plugins/blog::posts.widget_categories'),
            'description' => trans('plugins/blog::posts.widget_categories_description'),
            'display_posts_count' => 'yes',
            'category_ids' => [],
        ]);
    }

    protected function data(): array|Collection
    {
        $config = $this->getConfig();

        $categoryIds = Arr::get($config, 'category_ids', []);

        $displayPostsCount = Arr::get($config, 'display_posts_count', 'yes') !== 'no';

        $categories = Category::query()
            ->select(['id', 'name'])
            ->with('slugable')
            ->when($displayPostsCount, function ($query) {
                return $query->withCount([
                    'posts' => fn ($query) => $query->wherePublished('posts.status'),
                ]);
            })
            ->wherePublished()
            ->when($categoryIds, function ($query) use ($categoryIds) {
                return $query->whereIn('id', $categoryIds);
            }, function ($query) {
                return $query
                    ->take(5)
                    ->where(fn ($query) => $query->whereNull('parent_id')->orWhere('parent_id', 0));
            })
            ->orderBy('order')
            ->orderByDesc('created_at')
            ->get();

        if ($displayPostsCount) {
            $this->addChildrenPostsCount($categories);
        }

        return compact('categories');
    }

    /**
     * A category page lists the posts of the category and of its published children, so categories
     * having children must count those posts too, without counting a shared post twice.
     */
    protected function addChildrenPostsCount(EloquentCollection $categories): void
    {
        if ($categories->isEmpty()) {
            return;
        }

        $childIdsByParent = Category::query()
            ->wherePublished()
            ->whereIn('parent_id', $categories->modelKeys())
            ->whereColumn('id', '!=', 'parent_id')
            ->select(['id', 'parent_id'])
            ->get()
            ->groupBy('parent_id')
            ->map(fn (EloquentCollection $children) => $children->pluck('id')->all());

        foreach ($categories as $category) {
            $childIds = $childIdsByParent->get($category->getKey(), []);

            if (! $childIds) {
                continue;
            }

            $category->posts_count = Post::query()
                ->wherePublished()
                ->whereHas('categories', fn ($query) => $query->whereIn(
                    'categories.id',
                    array_merge([$category->getKey()], $childIds)
                ))
                ->count();
        }
    }

    protected function settingForm(): WidgetForm|string|null
    {
        $data = $this->getConfig();

        $categories = Category::query()->pluck('name', 'id')->all();
        $categoryIds = Arr::get($data, 'category_ids', []);

        if (! is_array($categoryIds)) {
            $categoryIds = $categoryIds ? explode(',', $categoryIds) : null;
        }

        return WidgetForm::createFromArray($data)
            ->add('name', TextField::class, NameFieldOption::make())
            ->add(
                'category_ids',
                SelectField::class,
                SelectFieldOption::make()
                    ->label(trans('plugins/blog::base.choose_categories'))
                    ->choices($categories)
                    ->selected($categoryIds)
                    ->searchable()
                    ->multiple()
            )
            ->add(
                'display_posts_count',
                SelectField::class,
                SelectFieldOption::make()
                    ->label(trans('plugins/blog::base.display_posts_count'))
                    ->choices([
                        'yes' => trans('plugins/blog::base.yes'),
                        'no' => trans('plugins/blog::base.no'),
                    ])
                    ->selected($data['display_posts_count'])
            );
    }

    protected function requiredPlugins(): array
    {
        return ['blog'];
    }
}
