<?php

namespace Botble\Marketplace\Forms\Settings\Concerns;

use Botble\Base\Forms\FieldOptions\MultiChecklistFieldOption;
use Botble\Base\Forms\FieldOptions\NumberFieldOption;
use Botble\Base\Forms\FieldOptions\OnOffFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\MultiCheckListField;
use Botble\Base\Forms\Fields\NumberField;
use Botble\Base\Forms\Fields\OnOffCheckboxField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Marketplace\Enums\MarketplaceModeEnum;
use Botble\Marketplace\Facades\MarketplaceHelper;
use Botble\Payment\Enums\PaymentMethodEnum;

/**
 * The marketplace mode selector and the vendor subscription settings.
 *
 * All three modes' fields are always rendered and wrapped in a collapsible keyed on the mode
 * select, so switching mode shows the right section immediately without a save. Because
 * every field is posted whatever the mode, all sets of values survive a switch. Hybrid shows
 * the commission block and this one at the same time.
 */
trait HasSubscriptionFields
{
    protected function addMarketplaceModeField(): static
    {
        return $this->add('mode', 'customSelect', [
            'label' => trans('plugins/marketplace::subscription.settings.mode'),
            'selected' => MarketplaceHelper::getMode(),
            'choices' => MarketplaceModeEnum::labels(),
            'help_block' => [
                'text' => trans('plugins/marketplace::subscription.settings.mode_helper'),
            ],
        ]);
    }

    protected function addSubscriptionFields(): static
    {
        return $this
            ->addOpenModeCollapsible([MarketplaceModeEnum::SUBSCRIPTION, MarketplaceModeEnum::HYBRID])
            ->add('subscription_settings_title', 'html', [
                'html' => sprintf(
                    '<hr><h4>%s</h4><p class="text-muted">%s</p>'
                    . '<p><a href="%s" class="btn btn-sm btn-outline-primary">%s</a></p>',
                    e(trans('plugins/marketplace::subscription.settings.title')),
                    e(trans('plugins/marketplace::subscription.settings.description')),
                    e(route('marketplace.subscription-plans.index')),
                    e(trans('plugins/marketplace::subscription.settings.manage_plans'))
                ),
            ])
            ->add(
                'enable_public_subscription_plans',
                OnOffCheckboxField::class,
                OnOffFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.settings.enable_public_plans'))
                    ->helperText(trans('plugins/marketplace::subscription.settings.enable_public_plans_helper'))
                    ->value(MarketplaceHelper::getSetting('enable_public_subscription_plans', false))
            )
            ->add(
                'subscription_unpublish_products_on_expired',
                OnOffCheckboxField::class,
                OnOffFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.settings.unpublish_products_on_expired'))
                    ->helperText(trans('plugins/marketplace::subscription.settings.unpublish_products_on_expired_helper'))
                    ->value(MarketplaceHelper::shouldUnpublishProductsOnSubscriptionExpired())
            )
            ->add(
                'subscription_grace_period_days',
                NumberField::class,
                NumberFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.settings.grace_period_days'))
                    ->helperText(trans('plugins/marketplace::subscription.settings.grace_period_days_helper'))
                    ->value(MarketplaceHelper::subscriptionGracePeriodDays())
                    ->addAttribute('min', 0)
            )
            ->add(
                'subscription_reminder_days',
                TextField::class,
                TextFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.settings.reminder_days'))
                    ->helperText(trans('plugins/marketplace::subscription.settings.reminder_days_helper'))
                    ->value(MarketplaceHelper::getSetting('subscription_reminder_days', '7,3,1'))
            )
            ->add(
                'subscription_allow_balance_payment',
                OnOffCheckboxField::class,
                OnOffFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.settings.allow_balance_payment'))
                    ->value(MarketplaceHelper::getSetting('subscription_allow_balance_payment', true))
            )
            ->add(
                'subscription_require_admin_approval',
                OnOffCheckboxField::class,
                OnOffFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.settings.require_admin_approval'))
                    ->helperText(trans('plugins/marketplace::subscription.settings.require_admin_approval_helper'))
                    ->value(MarketplaceHelper::subscriptionRequiresAdminApproval())
            )
            ->add(
                'subscription_allow_vendor_cancel',
                OnOffCheckboxField::class,
                OnOffFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.settings.allow_vendor_cancel'))
                    ->helperText(trans('plugins/marketplace::subscription.settings.allow_vendor_cancel_helper'))
                    ->value(MarketplaceHelper::getSetting('subscription_allow_vendor_cancel', true))
            )
            ->add(
                'subscription_tax_enabled',
                OnOffCheckboxField::class,
                OnOffFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.settings.tax_enabled'))
                    ->helperText(trans('plugins/marketplace::subscription.settings.tax_enabled_helper'))
                    ->value(MarketplaceHelper::getSetting('subscription_tax_enabled', false))
            )
            ->add(
                'subscription_invoice_prefix',
                TextField::class,
                TextFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.settings.invoice_prefix'))
                    ->helperText(trans('plugins/marketplace::subscription.settings.invoice_prefix_helper'))
                    ->value(MarketplaceHelper::getSetting('subscription_invoice_prefix', 'SUB-'))
            )
            ->add(
                'subscription_payment_methods[]',
                MultiCheckListField::class,
                MultiChecklistFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.settings.payment_methods'))
                    ->helperText(trans('plugins/marketplace::subscription.settings.payment_methods_helper'))
                    ->choices(PaymentMethodEnum::labels())
                    ->selected(MarketplaceHelper::subscriptionPaymentMethods())
            )
            ->addCloseModeCollapsible([MarketplaceModeEnum::SUBSCRIPTION, MarketplaceModeEnum::HYBRID]);
    }
}
