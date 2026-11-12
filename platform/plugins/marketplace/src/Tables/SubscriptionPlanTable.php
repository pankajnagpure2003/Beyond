<?php

namespace Botble\Marketplace\Tables;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Facades\BaseHelper;
use Botble\Ecommerce\Tables\Formatters\PriceFormatter;
use Botble\Marketplace\Enums\SubscriptionDurationUnitEnum;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Table\Abstracts\TableAbstract;
use Botble\Table\Actions\DeleteAction;
use Botble\Table\Actions\EditAction;
use Botble\Table\BulkActions\DeleteBulkAction;
use Botble\Table\Columns\Column;
use Botble\Table\Columns\CreatedAtColumn;
use Botble\Table\Columns\IdColumn;
use Botble\Table\Columns\NameColumn;
use Botble\Table\Columns\StatusColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;

class SubscriptionPlanTable extends TableAbstract
{
    public function setup(): void
    {
        $this
            ->model(SubscriptionPlan::class)
            ->addActions([
                EditAction::make()->route('marketplace.subscription-plans.edit'),
                DeleteAction::make()->route('marketplace.subscription-plans.destroy'),
            ]);
    }

    public function ajax(): JsonResponse
    {
        $data = $this->table
            ->eloquent($this->query())
            ->formatColumn('price', PriceFormatter::class)
            ->editColumn('duration_value', function (SubscriptionPlan $item): string {
                if ($item->isLifetime()) {
                    return SubscriptionDurationUnitEnum::getLabel(SubscriptionDurationUnitEnum::LIFETIME);
                }

                return $item->duration_value . ' ' . SubscriptionDurationUnitEnum::getLabel($item->duration_unit);
            })
            ->editColumn('product_limit', function (SubscriptionPlan $item): string {
                $limit = $item->getOption('product_limit');

                return $limit < 0 ? '&infin;' : (string) $limit;
            })
            ->editColumn('is_default', function (SubscriptionPlan $item): string {
                return $item->is_default
                    ? BaseHelper::renderBadge(trans('core/base::base.yes'), 'success')
                    : '&mdash;';
            });

        return $this->toJson($data);
    }

    public function query(): Relation|Builder|QueryBuilder
    {
        $query = $this
            ->getModel()
            ->query()
            ->select([
                'id',
                'name',
                'price',
                'duration_value',
                'duration_unit',
                'options',
                'is_default',
                'order',
                'status',
                'created_at',
            ])
            ->orderBy('order')
            ->orderBy('price');

        return $this->applyScopes($query);
    }

    public function columns(): array
    {
        return [
            IdColumn::make(),
            NameColumn::make()->route('marketplace.subscription-plans.edit'),
            Column::formatted('price')
                ->title(trans('plugins/marketplace::subscription.plans.form.price'))
                ->width(120),
            Column::make('duration_value')
                ->title(trans('plugins/marketplace::subscription.plans.form.duration_value'))
                ->width(140)
                ->orderable(false)
                ->searchable(false),
            Column::make('product_limit')
                ->title(trans('plugins/marketplace::subscription.plans.form.product_limit'))
                ->width(120)
                ->orderable(false)
                ->searchable(false),
            Column::make('is_default')
                ->title(trans('plugins/marketplace::subscription.plans.form.is_default'))
                ->width(120)
                ->searchable(false),
            CreatedAtColumn::make(),
            StatusColumn::make(),
        ];
    }

    public function buttons(): array
    {
        return $this->addCreateButton(
            route('marketplace.subscription-plans.create'),
            'marketplace.subscription-plans.create'
        );
    }

    public function bulkActions(): array
    {
        return [
            DeleteBulkAction::make()->permission('marketplace.subscription-plans.destroy'),
        ];
    }

    public function getBulkChanges(): array
    {
        return [
            'name' => [
                'title' => trans('plugins/marketplace::subscription.plans.form.name'),
                'type' => 'text',
                'validate' => 'required|max:120',
            ],
            'status' => [
                'title' => trans('core/base::tables.status'),
                'type' => 'select',
                'choices' => BaseStatusEnum::labels(),
                'validate' => 'required|in:' . implode(',', BaseStatusEnum::values()),
            ],
        ];
    }
}
