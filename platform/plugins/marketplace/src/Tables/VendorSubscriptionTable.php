<?php

namespace Botble\Marketplace\Tables;

use Botble\Base\Facades\BaseHelper;
use Botble\Base\Facades\Html;
use Botble\Ecommerce\Models\Customer;
use Botble\Ecommerce\Tables\Formatters\PriceFormatter;
use Botble\Marketplace\Enums\SubscriptionStatusEnum;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Marketplace\Models\VendorSubscription;
use Botble\Table\Abstracts\TableAbstract;
use Botble\Table\Actions\DeleteAction;
use Botble\Table\Actions\EditAction;
use Botble\Table\BulkActions\DeleteBulkAction;
use Botble\Table\Columns\Column;
use Botble\Table\Columns\CreatedAtColumn;
use Botble\Table\Columns\IdColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;

class VendorSubscriptionTable extends TableAbstract
{
    public function setup(): void
    {
        $this
            ->model(VendorSubscription::class)
            ->addActions([
                EditAction::make()->route('marketplace.vendor-subscriptions.edit'),
                DeleteAction::make()->route('marketplace.vendor-subscriptions.destroy'),
            ]);
    }

    public function ajax(): JsonResponse
    {
        $data = $this->table
            ->eloquent($this->query())
            ->formatColumn('amount', PriceFormatter::class)
            ->editColumn('customer_id', function (VendorSubscription $item) {
                if (! $item->customer?->id) {
                    return '&mdash;';
                }

                $name = BaseHelper::clean($item->customer->name);

                if (! $this->hasPermission('customers.edit')) {
                    return $name;
                }

                return Html::link(route('customers.edit', $item->customer->id), $name)->toHtml();
            })
            ->editColumn('subscription_plan_id', fn (VendorSubscription $item) => BaseHelper::clean($item->planName()))
            ->editColumn('ends_at', function (VendorSubscription $item): string {
                if ($item->isLifetime()) {
                    return trans('plugins/marketplace::subscription.subscriptions.lifetime');
                }

                // A pending row has not been given a period yet.
                if (! $item->ends_at) {
                    return '&mdash;';
                }

                // "in 3 weeks" answers the admin's actual question; the exact date stays
                // one hover away rather than taking the column width.
                return sprintf(
                    '<span title="%s">%s</span>',
                    e(BaseHelper::formatDate($item->ends_at)),
                    e($item->ends_at->diffForHumans())
                );
            })
            ->editColumn('status', fn (VendorSubscription $item) => $item->status->toHtml());

        return $this->toJson($data);
    }

    public function query(): Relation|Builder|QueryBuilder
    {
        $query = $this
            ->getModel()
            ->query()
            ->select([
                'id',
                'customer_id',
                'subscription_plan_id',
                'plan_data',
                'amount',
                'currency',
                'status',
                'starts_at',
                'ends_at',
                'payment_channel',
                'created_at',
            ])
            ->with(['customer'])
            // Pending requests are what the admin actually needs to act on.
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [SubscriptionStatusEnum::PENDING])
            ->orderByDesc('id');

        return $this->applyScopes($query);
    }

    public function columns(): array
    {
        return [
            IdColumn::make(),
            Column::make('customer_id')
                ->title(trans('plugins/marketplace::subscription.subscriptions.vendor'))
                ->alignStart(),
            Column::make('subscription_plan_id')
                ->title(trans('plugins/marketplace::subscription.subscriptions.plan'))
                ->alignStart()
                ->orderable(false)
                ->searchable(false),
            Column::formatted('amount')
                ->title(trans('plugins/marketplace::subscription.subscriptions.amount'))
                ->width(120),
            Column::make('ends_at')
                ->title(trans('plugins/marketplace::subscription.subscriptions.ends_at'))
                ->width(160),
            CreatedAtColumn::make(),
            Column::make('status')
                ->title(trans('core/base::tables.status'))
                ->width(120),
        ];
    }

    public function bulkActions(): array
    {
        return [
            DeleteBulkAction::make()->permission('marketplace.vendor-subscriptions.destroy'),
        ];
    }

    public function getFilters(): array
    {
        return [
            'status' => [
                'title' => trans('core/base::tables.status'),
                'type' => 'select',
                'choices' => SubscriptionStatusEnum::labels(),
                'validate' => 'required|in:' . implode(',', SubscriptionStatusEnum::values()),
            ],
            // Both resolve through 'callback' so their queries run only when the admin
            // opens that filter, not on every render of the table.
            'subscription_plan_id' => [
                'title' => trans('plugins/marketplace::subscription.subscriptions.plan'),
                'type' => 'select-search',
                'validate' => 'required|string',
                'callback' => fn () => $this->planChoices(),
            ],
            'customer_id' => [
                'title' => trans('plugins/marketplace::subscription.subscriptions.vendor'),
                'type' => 'select-search',
                'validate' => 'required|string',
                'callback' => fn () => $this->vendorChoices(),
            ],
        ];
    }

    /**
     * Only plans that some subscription actually references — filtering by a plan nobody
     * bought would return an empty table for no useful reason.
     *
     * @return array<int|string, string>
     */
    protected function planChoices(): array
    {
        return SubscriptionPlan::query()
            ->whereIn('id', VendorSubscription::query()->select('subscription_plan_id'))
            ->orderBy('order')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Only vendors who hold at least one subscription, for the same reason.
     *
     * @return array<int|string, string>
     */
    protected function vendorChoices(): array
    {
        return Customer::query()
            ->whereIn('id', VendorSubscription::query()->select('customer_id'))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function buttons(): array
    {
        return $this->addCreateButton(
            route('marketplace.vendor-subscriptions.create'),
            'marketplace.vendor-subscriptions.create'
        );
    }
}
