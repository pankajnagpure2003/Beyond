<?php

namespace Botble\Sitemap\Http\Requests;

use Botble\Base\Rules\OnOffRule;
use Botble\Support\Http\Requests\Request;
use Botble\Theme\Supports\AiCrawlerPolicy;
use Illuminate\Validation\Rule;

class SitemapSettingRequest extends Request
{
    public function rules(): array
    {
        return apply_filters('sitemap_settings_validation_rules', [
            'sitemap_enabled' => [new OnOffRule()],
            'sitemap_items_per_page' => ['nullable', 'integer', 'min:10', 'max:100000'],
            'sitemap_pages_enabled' => [new OnOffRule()],
            'indexnow_enabled' => [new OnOffRule()],
            'indexnow_api_key' => ['nullable', 'string', 'uuid', 'max:255'],
            'llms_txt_enabled' => [new OnOffRule()],
            'llms_txt_page_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'llms_txt_post_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'llms_full_txt_enabled' => [new OnOffRule()],
            'ai_crawler_policy' => ['nullable', 'string', Rule::in(AiCrawlerPolicy::policies())],
        ]);
    }
}
