<?php

namespace Botble\Blog\Forms\Settings;

use Botble\Base\Forms\FieldOptions\OnOffFieldOption;
use Botble\Base\Forms\FieldOptions\SelectFieldOption;
use Botble\Base\Forms\Fields\OnOffCheckboxField;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Blog\Http\Requests\Settings\BlogSettingRequest;
use Botble\Setting\Forms\SettingForm;

class BlogSettingForm extends SettingForm
{
    public function setup(): void
    {
        parent::setup();

        $this
            ->setSectionTitle(trans('plugins/blog::base.settings.title'))
            ->setSectionDescription(trans('plugins/blog::base.settings.description'))
            ->setValidatorClass(BlogSettingRequest::class)
            ->add(
                'blog_post_schema_enabled',
                OnOffCheckboxField::class,
                OnOffFieldOption::make()
                ->label(trans('plugins/blog::base.settings.enable_blog_post_schema'))
                ->defaultValue($targetValue = ((bool) setting('blog_post_schema_enabled', true)))
                ->helperText(trans('plugins/blog::base.settings.enable_blog_post_schema_description'))
            )
            ->addOpenCollapsible('blog_post_schema_enabled', '1', $targetValue)
            ->add(
                'blog_post_schema_type',
                SelectField::class,
                SelectFieldOption::make()
                    ->label(trans('plugins/blog::base.settings.schema_type'))
                    ->choices([
                        'BlogPosting' => 'BlogPosting',
                        'Article' => 'Article',
                        'NewsArticle' => 'NewsArticle',
                        'News' => 'News',
                    ])
                    ->selected(setting('blog_post_schema_type', 'BlogPosting'))
            )
            ->addCloseCollapsible('blog_post_schema_enabled', '1')
            ->add(
                'blog_heading_anchors_enabled',
                OnOffCheckboxField::class,
                OnOffFieldOption::make()
                    ->label(trans('plugins/blog::base.settings.enable_heading_anchors'))
                    ->defaultValue((bool) setting('blog_heading_anchors_enabled', false))
                    ->helperText(trans('plugins/blog::base.settings.enable_heading_anchors_description'))
            );
    }
}
