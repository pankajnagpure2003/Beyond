<?php

namespace Botble\Marketplace\Http\Controllers;

use Botble\Base\Forms\FormBuilder;
use Botble\Base\Http\Actions\DeleteResourceAction;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\Base\Supports\Breadcrumb;
use Botble\Marketplace\Forms\VendorSubscriptionForm;
use Botble\Marketplace\Http\Requests\RejectVendorSubscriptionRequest;
use Botble\Marketplace\Http\Requests\VendorSubscriptionRequest;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Marketplace\Models\Vendor;
use Botble\Marketplace\Models\VendorSubscription;
use Botble\Marketplace\Services\ManageVendorSubscriptionService;
use Botble\Marketplace\Tables\VendorSubscriptionTable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use LogicException;

class VendorSubscriptionController extends BaseController
{
    public function __construct(protected ManageVendorSubscriptionService $manageService)
    {
    }

    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()
            ->add(
                trans('plugins/marketplace::subscription.subscriptions.name'),
                route('marketplace.vendor-subscriptions.index')
            );
    }

    public function index(VendorSubscriptionTable $table): View|JsonResponse
    {
        $this->pageTitle(trans('plugins/marketplace::subscription.subscriptions.name'));

        return $table->renderTable();
    }

    public function create(FormBuilder $formBuilder): string
    {
        $this->pageTitle(trans('plugins/marketplace::subscription.subscriptions.assign'));

        return $formBuilder->create(VendorSubscriptionForm::class)->renderForm();
    }

    public function store(VendorSubscriptionRequest $request): BaseHttpResponse
    {
        $vendor = Vendor::query()->findOrFail($request->input('customer_id'));
        $plan = SubscriptionPlan::query()->findOrFail($request->input('subscription_plan_id'));
        $endsAt = $request->filled('ends_at') ? Carbon::parse($request->input('ends_at'))->endOfDay() : null;

        $subscription = $this->manageService->assign($vendor, $plan, $endsAt);

        if ($request->boolean('auto_renew')) {
            $subscription->fill(['auto_renew' => true])->save();
        }

        return $this
            ->httpResponse()
            ->setPreviousUrl(route('marketplace.vendor-subscriptions.index'))
            ->setNextUrl(route('marketplace.vendor-subscriptions.edit', $subscription->getKey()))
            ->setMessage(trans('plugins/marketplace::subscription.actions.assigned_success'));
    }

    public function edit(VendorSubscription $vendorSubscription): View
    {
        $this->pageTitle(trans('plugins/marketplace::subscription.subscriptions.edit'));

        $vendorSubscription->load(['customer', 'plan', 'logs.user']);

        return view('plugins/marketplace::subscriptions.edit', compact('vendorSubscription'));
    }

    public function approve(VendorSubscription $vendorSubscription): BaseHttpResponse
    {
        return $this->runAction(
            fn () => $this->manageService->approve($vendorSubscription),
            trans('plugins/marketplace::subscription.actions.approved_success')
        );
    }

    public function reject(
        VendorSubscription $vendorSubscription,
        RejectVendorSubscriptionRequest $request
    ): BaseHttpResponse {
        return $this->runAction(
            fn () => $this->manageService->reject($vendorSubscription, $request->input('reason')),
            trans('plugins/marketplace::subscription.actions.rejected_success')
        );
    }

    public function extend(VendorSubscription $vendorSubscription, Request $request): BaseHttpResponse
    {
        $days = (int) $request->validate(['days' => 'required|integer|min:1|max:3650'])['days'];

        return $this->runAction(
            fn () => $this->manageService->extend($vendorSubscription, $days),
            trans('plugins/marketplace::subscription.actions.extended_success')
        );
    }

    public function cancel(VendorSubscription $vendorSubscription): BaseHttpResponse
    {
        return $this->runAction(
            fn () => $this->manageService->cancel($vendorSubscription),
            trans('plugins/marketplace::subscription.actions.cancelled_success')
        );
    }

    public function destroy(VendorSubscription $vendorSubscription): DeleteResourceAction
    {
        return DeleteResourceAction::make($vendorSubscription);
    }

    /**
     * The service throws on an illegal transition (e.g. approving something already
     * approved); surface that as an error instead of a 500.
     */
    protected function runAction(callable $action, string $message): BaseHttpResponse
    {
        try {
            $action();
        } catch (LogicException $exception) {
            return $this->httpResponse()->setError()->setMessage($exception->getMessage());
        }

        return $this->httpResponse()->setMessage($message);
    }
}
