<?php

namespace Botble\Sitemap\Forms\Settings;

use Botble\Base\Facades\BaseHelper;
use Botble\Base\Forms\FieldOptions\CheckboxFieldOption;
use Botble\Base\Forms\FieldOptions\HtmlFieldOption;
use Botble\Base\Forms\FieldOptions\NumberFieldOption;
use Botble\Base\Forms\FieldOptions\SelectFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\HtmlField;
use Botble\Base\Forms\Fields\NumberField;
use Botble\Base\Forms\Fields\OnOffCheckboxField;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Setting\Forms\SettingForm;
use Botble\Sitemap\Http\Requests\SitemapSettingRequest;
use Botble\Theme\Supports\AiCrawlerPolicy;
use Illuminate\Support\Facades\File;

class SitemapSettingForm extends SettingForm
{
    public function setup(): void
    {
        parent::setup();

        $this
            ->setSectionTitle(trans('packages/sitemap::sitemap.settings.title'))
            ->setSectionDescription(trans('packages/sitemap::sitemap.settings.description'))
            ->setValidatorClass(SitemapSettingRequest::class)
            ->add(
                'sitemap_enabled',
                OnOffCheckboxField::class,
                CheckboxFieldOption::make()
                    ->label(trans('packages/sitemap::sitemap.settings.enable_sitemap'))
                    ->value($sitemapEnabled = setting('sitemap_enabled', true))
                    ->helperText(trans('packages/sitemap::sitemap.settings.enable_sitemap_help', ['url' => url('sitemap.xml')]))
            )
            ->addOpenCollapsible('sitemap_enabled', '1', $sitemapEnabled)
            ->add(
                'sitemap_info',
                HtmlField::class,
                HtmlFieldOption::make()
                    ->content(view('packages/sitemap::partials.sitemap-info')->render())
            )
            ->add(
                'sitemap_items_per_page',
                NumberField::class,
                NumberFieldOption::make()
                    ->label(trans('packages/sitemap::sitemap.settings.sitemap_items_per_page'))
                    ->value(setting('sitemap_items_per_page', 1000))
                    ->helperText(trans('packages/sitemap::sitemap.settings.sitemap_items_per_page_help'))
                    ->min(10)
                    ->max(100000)
            )
            ->add(
                'sitemap_content_types_heading',
                HtmlField::class,
                HtmlFieldOption::make()
                    ->content('<h4 class="mt-3">' . trans('packages/sitemap::sitemap.settings.content_types_heading') . '</h4><p class="text-muted">' . trans('packages/sitemap::sitemap.settings.content_types_description') . '</p>')
            )
            ->add(
                'sitemap_pages_enabled',
                OnOffCheckboxField::class,
                CheckboxFieldOption::make()
                    ->label(trans('packages/sitemap::sitemap.settings.enable_pages_sitemap'))
                    ->value(setting('sitemap_pages_enabled', true))
                    ->helperText(trans('packages/sitemap::sitemap.settings.enable_pages_sitemap_help'))
            );

        $this
            ->add(
                'indexnow_enabled',
                OnOffCheckboxField::class,
                CheckboxFieldOption::make()
                    ->label(trans('packages/sitemap::sitemap.settings.indexnow_enabled'))
                    ->value($indexNowEnabled = setting('indexnow_enabled', 0))
                    ->helperText(trans('packages/sitemap::sitemap.settings.indexnow_enabled_help'))
            )
            ->addOpenCollapsible('indexnow_enabled', '1', $indexNowEnabled)
            ->add(
                'indexnow_api_key',
                TextField::class,
                TextFieldOption::make()
                    ->label(trans('packages/sitemap::sitemap.settings.indexnow_api_key'))
                    ->value(setting('indexnow_api_key'))
                    ->helperText(trans('packages/sitemap::sitemap.settings.indexnow_api_key_help'))
                    ->placeholder('xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx')
            )
            ->add(
                'indexnow_info',
                HtmlField::class,
                HtmlFieldOption::make()
                    ->content(view('packages/sitemap::partials.indexnow-info')->render())
            )
            ->addCloseCollapsible('indexnow_enabled', '1')
            ->addCloseCollapsible('sitemap_enabled', '1');

        $this
            ->add(
                'llms_txt_enabled',
                OnOffCheckboxField::class,
                CheckboxFieldOption::make()
                    ->label(trans('packages/sitemap::sitemap.settings.enable_llms_txt'))
                    ->value($llmsTxtEnabled = setting('llms_txt_enabled', true))
                    ->helperText(trans('packages/sitemap::sitemap.settings.enable_llms_txt_help', ['url' => url('llms.txt')]))
            )
            ->addOpenCollapsible('llms_txt_enabled', '1', (bool) $llmsTxtEnabled)
            ->add(
                'llms_txt_page_limit',
                NumberField::class,
                NumberFieldOption::make()
                    ->label(trans('packages/sitemap::sitemap.settings.llms_txt_page_limit'))
                    ->value(setting('llms_txt_page_limit', 100))
                    ->helperText(trans('packages/sitemap::sitemap.settings.llms_txt_limit_help'))
                    ->min(1)
                    ->max(1000)
            )
            ->add(
                'llms_txt_post_limit',
                NumberField::class,
                NumberFieldOption::make()
                    ->label(trans('packages/sitemap::sitemap.settings.llms_txt_post_limit'))
                    ->value(setting('llms_txt_post_limit', 50))
                    ->helperText(trans('packages/sitemap::sitemap.settings.llms_txt_limit_help'))
                    ->min(1)
                    ->max(1000)
            )
            ->add(
                'llms_full_txt_enabled',
                OnOffCheckboxField::class,
                CheckboxFieldOption::make()
                    ->label(trans('packages/sitemap::sitemap.settings.enable_llms_full_txt'))
                    ->value(setting('llms_full_txt_enabled', false))
                    ->helperText(trans('packages/sitemap::sitemap.settings.enable_llms_full_txt_help', ['url' => url('llms-full.txt')]))
            )
            ->addCloseCollapsible('llms_txt_enabled', '1');

        $this
            ->add(
                'ai_crawler_heading',
                HtmlField::class,
                HtmlFieldOption::make()
                    ->content('<h4 class="mt-3">' . trans('packages/sitemap::sitemap.settings.ai_crawler_heading') . '</h4><p class="text-muted">' . trans('packages/sitemap::sitemap.settings.ai_crawler_description') . '</p>')
            )
            ->add(
                'ai_crawler_policy',
                SelectField::class,
                SelectFieldOption::make()
                    ->label(trans('packages/sitemap::sitemap.settings.ai_crawler_policy'))
                    ->choices([
                        AiCrawlerPolicy::ALLOW_ALL => trans('packages/sitemap::sitemap.settings.ai_crawler_policy_allow_all'),
                        AiCrawlerPolicy::BLOCK_TRAINING => trans('packages/sitemap::sitemap.settings.ai_crawler_policy_block_training'),
                        AiCrawlerPolicy::BLOCK_ALL => trans('packages/sitemap::sitemap.settings.ai_crawler_policy_block_all'),
                    ])
                    ->selected(AiCrawlerPolicy::sanitizePolicy(setting('ai_crawler_policy')))
                    ->helperText(trans('packages/sitemap::sitemap.settings.ai_crawler_policy_help'))
            );

        // Saving writes the policy into public/robots.txt (the web server serves that file
        // before Laravel routing). If it is not writable the policy cannot be applied, so
        // warn before the admin saves a setting that would silently do nothing.
        $robotsTxtPath = apply_filters(FILTER_ROBOTS_TXT_PATH, public_path('robots.txt'));

        if (File::exists($robotsTxtPath) && ! File::isWritable($robotsTxtPath)) {
            $this->add(
                'ai_crawler_policy_static_file_warning',
                HtmlField::class,
                HtmlFieldOption::make()
                    ->content(
                        '<div class="alert alert-warning mb-0">'
                        . BaseHelper::clean(trans('packages/sitemap::sitemap.settings.ai_crawler_robots_not_writable', [
                            'path' => $robotsTxtPath,
                        ]))
                        . '</div>'
                    )
            );
        }
    }
}
