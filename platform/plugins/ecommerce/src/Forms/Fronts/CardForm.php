<?php

namespace Botble\Ecommerce\Forms\Fronts;

use Botble\Base\Facades\BaseHelper;
use Botble\Base\Forms\Fields\HtmlField;
use Botble\Ecommerce\Facades\EcommerceHelper;
use Botble\Theme\Facades\Theme;
use Botble\Theme\FormFront;

/**
 * Base form for the front-end pages that are rendered inside a centered card:
 * the customer auth pages (login, register, password reset) and the order
 * tracking page.
 *
 * The card chrome - optional banner, icon, heading and description - is
 * rendered by `plugins/ecommerce::forms.card` and styled by `front-card.css`.
 * Both keep the original `auth-card` class names, so a theme only has to style
 * that one component to restyle every page built on this form.
 */
abstract class CardForm extends FormFront
{
    public function setup(): void
    {
        Theme::asset()->add(
            'ecommerce-card-form-css',
            'vendor/core/plugins/ecommerce/css/front-card.css',
            version: EcommerceHelper::getAssetVersion()
        );

        $this
            ->contentOnly()
            ->template('plugins/ecommerce::forms.card');
    }

    /**
     * Add a full width submit button wrapped in a grid so it stretches to the
     * card width, and close the form right before it when rendering partially.
     */
    public function submitButton(string $label, ?string $icon = null, string $iconPosition = 'append'): static
    {
        $iconHtml = $icon ? BaseHelper::renderIcon($icon) : '';

        return $this
            ->add('openButtonWrap', HtmlField::class, [
                'html' => '<div class="d-grid">',
            ])
            ->add('submit', 'submit', [
                'label' =>
                    ($icon && $iconPosition === 'prepend' ? $iconHtml : '')
                    . $label
                    . ($icon && $iconPosition === 'append' ? $iconHtml : ''),
                'attr' => [
                    'class' => 'btn btn-primary btn-auth-submit',
                ],
            ])
            ->add('closeButtonWrap', HtmlField::class, [
                'html' => '</div>',
            ])
            ->setFormEndKey('openButtonWrap');
    }

    public function banner(string $banner): static
    {
        return $this->setFormOption('banner', $banner);
    }

    public function bannerDirection(string $direction): static
    {
        return $this->setFormOption('bannerDirection', $direction);
    }

    public function icon(string $icon): static
    {
        return $this->setFormOption('icon', $icon);
    }

    public function heading(string $heading): static
    {
        return $this->setFormOption('heading', $heading);
    }

    public function description(string $description): static
    {
        return $this->setFormOption('description', $description);
    }

    /**
     * Render the fields on their own, without the card chrome, for themes that
     * provide their own page layout.
     */
    public function ignoreBaseTemplate(): static
    {
        return $this
            ->banner('')
            ->icon('')
            ->heading('')
            ->description('')
            ->contentOnly();
    }
}
