<?php

namespace Botble\Marketplace\Providers;

use Botble\Marketplace\Commands\ExpireVendorSubscriptionsCommand;
use Botble\Marketplace\Facades\MarketplaceHelper;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class CommandServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            ExpireVendorSubscriptionsCommand::class,
        ]);

        $this->app->afterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule
                ->command(ExpireVendorSubscriptionsCommand::class)
                ->dailyAt('01:00')
                ->when(fn () => MarketplaceHelper::hasVendorSubscriptions());
        });
    }
}
