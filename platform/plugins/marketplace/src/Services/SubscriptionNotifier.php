<?php

namespace Botble\Marketplace\Services;

use Botble\Base\Facades\EmailHandler;
use Botble\Ecommerce\Facades\EcommerceHelper;
use Botble\Marketplace\Facades\MarketplaceHelper;
use Botble\Marketplace\Models\VendorSubscription;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Every subscription email in one place. Each method is a no-op when the admin has
 * disabled the matching template or the vendor has no email address.
 */
class SubscriptionNotifier
{
    public function activated(VendorSubscription $subscription): void
    {
        $this->sendToVendor($subscription, 'vendor-subscription-activated');
    }

    public function renewed(VendorSubscription $subscription): void
    {
        $this->sendToVendor($subscription, 'vendor-subscription-renewed');
    }

    public function rejected(VendorSubscription $subscription): void
    {
        $this->sendToVendor($subscription, 'vendor-subscription-rejected', [
            'rejected_reason' => (string) $subscription->rejected_reason,
        ]);
    }

    public function expired(VendorSubscription $subscription): void
    {
        $this->sendToVendor($subscription, 'vendor-subscription-expired');
    }

    public function expiring(VendorSubscription $subscription, int $daysLeft): bool
    {
        return $this->sendToVendor($subscription, 'vendor-subscription-expiring', [
            'days_left' => (string) $daysLeft,
        ]);
    }

    /**
     * An auto-renew attempt failed. Carries the date the grace period runs out, because
     * that is the deadline the vendor actually needs to act before.
     */
    public function renewalFailed(VendorSubscription $subscription): bool
    {
        $graceEndsAt = $subscription->ends_at
            ?->copy()
            ->addDays(MarketplaceHelper::subscriptionGracePeriodDays());

        return $this->sendToVendor($subscription, 'vendor-subscription-renewal-failed', [
            'grace_ends_at' => $graceEndsAt?->toDateString() ?: '',
        ]);
    }

    /**
     * Tell the admins a subscription is sitting in the approval queue.
     */
    public function pendingApproval(VendorSubscription $subscription): void
    {
        $this->send(function () use ($subscription): void {
            $mailer = $this->mailer('vendor-subscription-pending-approval');

            if (! $mailer) {
                return;
            }

            // This is the one template that goes to admins rather than the vendor, so the
            // shared variables are wrong for it twice over: the CTA would point at the
            // storefront vendor dashboard, and the greeting would address the admin by the
            // vendor's own name.
            $mailer->setVariableValues(array_merge($this->variables($subscription), [
                'subscription_url' => URL::route(
                    'marketplace.vendor-subscriptions.edit',
                    $subscription->getKey()
                ),
            ]));

            foreach (EcommerceHelper::getAdminNotificationEmails() as $email) {
                $mailer->sendUsingTemplate('vendor-subscription-pending-approval', $email);
            }
        }, 'vendor-subscription-pending-approval', $subscription);
    }

    /**
     * @return bool whether there was anything to send at all — false when the vendor has no
     *              address or the admin has switched this template off. A true return means
     *              the mail was handed to the mailer, not that delivery succeeded.
     */
    protected function sendToVendor(VendorSubscription $subscription, string $template, array $extra = []): bool
    {
        $vendor = $subscription->customer;

        if (! $vendor?->email) {
            return false;
        }

        $mailer = $this->mailer($template);

        if (! $mailer) {
            return false;
        }

        $this->send(function () use ($subscription, $template, $extra, $vendor, $mailer): void {
            $mailer->setVariableValues(array_merge($this->variables($subscription), $extra));
            $mailer->sendUsingTemplate($template, $vendor->email);
        }, $template, $subscription);

        return true;
    }

    /**
     * Notifications must never be able to fail the thing that triggered them: a dead mail
     * server would otherwise roll back an activation the vendor has already paid for, or
     * abort the rest of the nightly expiry run.
     */
    protected function send(callable $callback, string $template, VendorSubscription $subscription): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            Log::error('Failed to send vendor subscription email', [
                'template' => $template,
                'subscription_id' => $subscription->getKey(),
                'message' => $exception->getMessage(),
            ]);
        }
    }

    protected function mailer(string $template)
    {
        $mailer = EmailHandler::setModule(MARKETPLACE_MODULE_SCREEN_NAME);

        return $mailer->templateEnabled($template) ? $mailer : null;
    }

    protected function variables(VendorSubscription $subscription): array
    {
        $vendor = $subscription->customer;

        return [
            'vendor_name' => (string) $vendor?->name,
            'store_name' => (string) $vendor?->store?->name,
            'plan_name' => $subscription->planName(),
            'plan_price' => format_price($subscription->amount),
            'starts_at' => $subscription->starts_at ? $subscription->starts_at->toDateString() : '',
            'ends_at' => $subscription->ends_at
                ? $subscription->ends_at->toDateString()
                : trans('plugins/marketplace::subscription.vendor.never_expires'),
            'subscription_url' => URL::route('marketplace.vendor.subscriptions.index'),
        ];
    }
}
