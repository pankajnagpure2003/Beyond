<?php

namespace Botble\Marketplace\Http\Controllers;

use Botble\Base\Events\BeforeUpdateContentEvent;
use Botble\Base\Events\CreatedContentEvent;
use Botble\Base\Events\UpdatedContentEvent;
use Botble\Base\Forms\FormBuilder;
use Botble\Base\Http\Actions\DeleteResourceAction;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\Base\Supports\Breadcrumb;
use Botble\Marketplace\Forms\SubscriptionPlanForm;
use Botble\Marketplace\Http\Requests\SubscriptionPlanRequest;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Marketplace\Tables\SubscriptionPlanTable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

class SubscriptionPlanController extends BaseController
{
    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()
            ->add(
                trans('plugins/marketplace::subscription.plans.name'),
                route('marketplace.subscription-plans.index')
            );
    }

    public function index(SubscriptionPlanTable $table): View|JsonResponse
    {
        $this->pageTitle(trans('plugins/marketplace::subscription.plans.name'));

        return $table->renderTable();
    }

    public function create(FormBuilder $formBuilder): string
    {
        $this->pageTitle(trans('plugins/marketplace::subscription.plans.create'));

        return $formBuilder->create(SubscriptionPlanForm::class)->renderForm();
    }

    public function store(SubscriptionPlanRequest $request): BaseHttpResponse
    {
        $plan = new SubscriptionPlan();
        $plan->fill($this->attributes($request));
        $plan->fillOptions($this->options($request));
        $plan->save();

        event(new CreatedContentEvent('subscription-plan', $request, $plan));

        return $this
            ->httpResponse()
            ->setPreviousUrl(route('marketplace.subscription-plans.index'))
            ->setNextUrl(route('marketplace.subscription-plans.edit', $plan->getKey()))
            ->withCreatedSuccessMessage();
    }

    public function edit(SubscriptionPlan $subscriptionPlan, FormBuilder $formBuilder): string
    {
        $this->pageTitle(trans('core/base::forms.edit_item', ['name' => $subscriptionPlan->name]));

        return $formBuilder->create(SubscriptionPlanForm::class, ['model' => $subscriptionPlan])->renderForm();
    }

    public function update(SubscriptionPlan $subscriptionPlan, SubscriptionPlanRequest $request): BaseHttpResponse
    {
        event(new BeforeUpdateContentEvent($request, $subscriptionPlan));

        $subscriptionPlan->fill($this->attributes($request));
        $subscriptionPlan->fillOptions($this->options($request));
        $subscriptionPlan->save();

        event(new UpdatedContentEvent('subscription-plan', $request, $subscriptionPlan));

        return $this
            ->httpResponse()
            ->setPreviousUrl(route('marketplace.subscription-plans.index'))
            ->withUpdatedSuccessMessage();
    }

    public function destroy(SubscriptionPlan $subscriptionPlan): DeleteResourceAction
    {
        return DeleteResourceAction::make($subscriptionPlan);
    }

    protected function attributes(SubscriptionPlanRequest $request): array
    {
        return Arr::except($request->validated(), ['options']);
    }

    /**
     * Unchecked OnOff fields are absent from the payload, so a missing flag means 0
     * rather than "keep the previous value". Numeric quotas fall back to their default,
     * which keeps product_limit unlimited instead of silently dropping it to zero.
     */
    protected function options(SubscriptionPlanRequest $request): array
    {
        $submitted = (array) $request->input('options', []);
        $booleans = ['allow_digital_products', 'allow_coupons', 'allow_product_import'];
        $options = [];

        foreach (SubscriptionPlan::defaultOptions() as $key => $default) {
            $fallback = in_array($key, $booleans, true) ? 0 : $default;
            // Per-key cast: commission_* are floats, everything else stays integer.
            $options[$key] = SubscriptionPlan::castOptionValue($key, $submitted[$key] ?? $fallback);
        }

        return $options;
    }
}
