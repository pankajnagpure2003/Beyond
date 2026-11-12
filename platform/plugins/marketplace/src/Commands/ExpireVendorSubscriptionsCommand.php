<?php

namespace Botble\Marketplace\Commands;

use Botble\Marketplace\Enums\SubscriptionStatusEnum;
use Botble\Marketplace\Facades\MarketplaceHelper;
use Botble\Marketplace\Models\VendorSubscription;
use Botble\Marketplace\Services\SubscriptionRenewalService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'cms:marketplace:subscriptions:expire',
    description: 'Renew, remind about and expire vendor subscriptions'
)]
class ExpireVendorSubscriptionsCommand extends Command
{
    public function handle(SubscriptionRenewalService $renewalService): int
    {
        if (! MarketplaceHelper::hasVendorSubscriptions()) {
            $this->components->info('Marketplace has no vendor subscriptions, nothing to do.');

            return self::SUCCESS;
        }

        $renewed = $this->autoRenew($renewalService);
        $reminded = $this->sendReminders($renewalService);
        $expired = $this->expire($renewalService);

        $this->components->info(
            sprintf('Renewed %d, reminded %d, expired %d vendor subscription(s).', $renewed, $reminded, $expired)
        );

        return self::SUCCESS;
    }

    protected function autoRenew(SubscriptionRenewalService $renewalService): int
    {
        $count = 0;

        $this->dueQuery()
            ->where('auto_renew', true)
            ->chunkById(100, function ($subscriptions) use ($renewalService, &$count): void {
                foreach ($subscriptions as $subscription) {
                    $count += $renewalService->autoRenew($subscription) ? 1 : 0;
                }
            });

        return $count;
    }

    protected function sendReminders(SubscriptionRenewalService $renewalService): int
    {
        $days = MarketplaceHelper::subscriptionReminderDays();

        if (! $days) {
            return 0;
        }

        $count = 0;
        $horizon = Carbon::now()->addDays(max($days))->endOfDay();

        VendorSubscription::query()
            ->where('status', SubscriptionStatusEnum::ACTIVE)
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', Carbon::now())
            ->where('ends_at', '<=', $horizon)
            ->chunkById(100, function ($subscriptions) use ($renewalService, $days, &$count): void {
                foreach ($subscriptions as $subscription) {
                    $count += $renewalService->remind($subscription, $days) ? 1 : 0;
                }
            });

        return $count;
    }

    protected function expire(SubscriptionRenewalService $renewalService): int
    {
        $count = 0;
        $cutoff = Carbon::now()->subDays(MarketplaceHelper::subscriptionGracePeriodDays());

        VendorSubscription::query()
            ->where('status', SubscriptionStatusEnum::ACTIVE)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $cutoff)
            ->chunkById(100, function ($subscriptions) use ($renewalService, &$count): void {
                foreach ($subscriptions as $subscription) {
                    $renewalService->expire($subscription);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Subscriptions close enough to their end date to be worth renewing now.
     */
    protected function dueQuery()
    {
        return VendorSubscription::query()
            ->where('status', SubscriptionStatusEnum::ACTIVE)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', Carbon::now()->addDay()->endOfDay());
    }
}
