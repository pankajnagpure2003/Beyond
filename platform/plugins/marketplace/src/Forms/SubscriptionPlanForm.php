<?php

namespace Botble\Marketplace\Forms;

use Botble\Base\Forms\FieldOptions\NameFieldOption;
use Botble\Base\Forms\FieldOptions\NumberFieldOption;
use Botble\Base\Forms\FieldOptions\OnOffFieldOption;
use Botble\Base\Forms\FieldOptions\SelectFieldOption;
use Botble\Base\Forms\FieldOptions\StatusFieldOption;
use Botble\Base\Forms\FieldOptions\TextareaFieldOption;
use Botble\Base\Forms\Fields\NumberField;
use Botble\Base\Forms\Fields\OnOffCheckboxField;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextareaField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\FormAbstract;
use Botble\Marketplace\Enums\MarketplaceModeEnum;
use Botble\Marketplace\Enums\SubscriptionDurationUnitEnum;
use Botble\Marketplace\Facades\MarketplaceHelper;
use Botble\Marketplace\Http\Requests\SubscriptionPlanRequest;
use Botble\Marketplace\Models\SubscriptionPlan;

class SubscriptionPlanForm extends FormAbstract
{
    public function setup(): void
    {
        // getModel() returns the raw form options array until ->model() is called, so a
        // create form has no plan to read defaults from.
        $model = $this->getModel();
        $plan = $model instanceof SubscriptionPlan ? $model : null;

        $this
            ->model(SubscriptionPlan::class)
            ->setValidatorClass(SubscriptionPlanRequest::class)
            ->columns(12)
            ->add('name', TextField::class, NameFieldOption::make()->required()->colspan(12))
            ->add(
                'description',
                TextareaField::class,
                TextareaFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.plans.form.description'))
                    ->rows(3)
                    ->colspan(12)
            )
            ->add(
                'price',
                NumberField::class,
                NumberFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.plans.form.price'))
                    ->helperText(trans('plugins/marketplace::subscription.plans.form.price_helper'))
                    ->defaultValue(0)
                    ->colspan(4)
                    ->addAttribute('min', 0)
                    ->addAttribute('step', 'any')
            )
            ->add(
                'duration_value',
                NumberField::class,
                NumberFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.plans.form.duration_value'))
                    ->defaultValue(1)
                    ->colspan(4)
                    ->addAttribute('min', 1)
            )
            ->add(
                'duration_unit',
                SelectField::class,
                SelectFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.plans.form.duration_unit'))
                    ->choices(SubscriptionDurationUnitEnum::labels())
                    ->selected($plan?->duration_unit ?: SubscriptionDurationUnitEnum::MONTH)
                    ->colspan(4)
            )
            ->add(
                'order',
                NumberField::class,
                NumberFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.plans.form.order'))
                    ->defaultValue(0)
                    ->colspan(6)
                    ->addAttribute('min', 0)
            )
            ->add(
                'is_default',
                OnOffCheckboxField::class,
                OnOffFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.plans.form.is_default'))
                    ->helperText(trans('plugins/marketplace::subscription.plans.form.is_default_helper'))
                    ->colspan(6)
            )
            ->add('status', SelectField::class, StatusFieldOption::make()->colspan(12));

        $this->addOptionFields($plan);
    }

    /**
     * Quotas and feature flags are posted as options[key] and merged over
     * SubscriptionPlan::defaultOptions() when saved.
     */
    protected function addOptionFields(?SubscriptionPlan $plan): void
    {
        $options = $plan?->getOptions() ?: SubscriptionPlan::defaultOptions();

        $this->add('options_title', 'html', [
            'html' => sprintf('<hr><h4>%s</h4>', e(trans('plugins/marketplace::subscription.plans.form.options'))),
            'colspan' => 12,
        ]);

        foreach ($this->quotaFields() as $key => $helper) {
            $this->add(
                sprintf('options[%s]', $key),
                NumberField::class,
                NumberFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.plans.form.' . $key))
                    ->helperText($helper ? trans($helper) : null)
                    ->value($options[$key] ?? 0)
                    ->colspan(6)
                    ->addAttribute('min', -1)
            );
        }

        foreach (['allow_digital_products', 'allow_coupons', 'allow_product_import'] as $key) {
            $this->add(
                sprintf('options[%s]', $key),
                OnOffCheckboxField::class,
                OnOffFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.plans.form.' . $key))
                    ->value((bool) ($options[$key] ?? false))
                    ->colspan(6)
            );
        }

        $this->addCommissionOverrideFields($options);
    }

    /**
     * Per-plan commission rates. Hybrid only: in commission mode the global rate is the only
     * rate, and in subscription mode no commission is charged at all, so in both the fields
     * would be dead controls that silently do nothing.
     *
     * @param  array<string, mixed>  $options
     */
    protected function addCommissionOverrideFields(array $options): void
    {
        if (MarketplaceHelper::getMode() !== MarketplaceModeEnum::HYBRID) {
            return;
        }

        $this->add('commission_override_title', 'html', [
            'html' => sprintf(
                '<hr><h4>%s</h4><p class="text-muted">%s</p>',
                e(trans('plugins/marketplace::subscription.plans.form.commission_override')),
                e(trans('plugins/marketplace::subscription.plans.form.commission_override_helper'))
            ),
            'colspan' => 12,
        ]);

        foreach (['commission_percentage', 'commission_fixed'] as $key) {
            $this->add(
                sprintf('options[%s]', $key),
                NumberField::class,
                NumberFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.plans.form.' . $key))
                    ->helperText(trans('plugins/marketplace::subscription.plans.form.' . $key . '_helper'))
                    ->value($options[$key] ?? -1)
                    ->colspan(6)
                    ->addAttribute('min', -1)
                    ->addAttribute('step', 'any')
            );
        }
    }

    /**
     * @return array<string, string|null> Numeric option => helper text translation key.
     */
    protected function quotaFields(): array
    {
        return [
            'product_limit' => 'plugins/marketplace::subscription.plans.form.unlimited_helper',
            'featured_product_limit' => 'plugins/marketplace::subscription.plans.form.unlimited_helper',
            'listing_priority' => 'plugins/marketplace::subscription.plans.form.listing_priority_helper',
        ];
    }
}
