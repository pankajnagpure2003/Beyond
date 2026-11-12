<?php

namespace Botble\Ecommerce\Services;

use Botble\Base\Events\CreatedContentEvent;
use Botble\Base\Facades\BaseHelper;
use Botble\Ecommerce\Models\Product;
use Botble\Ecommerce\Models\ProductTag;
use Illuminate\Http\Request;

class StoreProductTagService
{
    public function execute(Request $request, Product $product): void
    {
        if (! $request->has('tag')) {
            return;
        }

        $tags = $product->tags->pluck('name')->map(fn ($name) => trim((string) $name))->all();

        $tagsInput = collect(json_decode((string) $request->input('tag'), true))
            ->pluck('value')
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (count($tags) != count($tagsInput) || count(array_diff($tags, $tagsInput)) > 0) {
            $product->tags()->detach();

            $tagIds = [];

            foreach ($tagsInput as $tagName) {
                // The name attribute is cast to SafeContent, which HTML-encodes on write (& => &amp;)
                // and decodes on read. Look up using the encoded form so existing tags are matched
                // instead of being re-created on every import.
                $tag = ProductTag::query()->where('name', BaseHelper::clean($tagName))->first();

                if ($tag === null) {
                    $tag = ProductTag::query()->create(['name' => $tagName]);

                    $request->merge(['slug' => $tagName]);

                    event(new CreatedContentEvent(PRODUCT_TAG_MODULE_SCREEN_NAME, $request, $tag));
                }

                $tagIds[] = $tag->getKey();
            }

            $product->tags()->sync(array_unique($tagIds));
        }
    }
}
