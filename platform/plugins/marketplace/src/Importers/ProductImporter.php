<?php

namespace Botble\Marketplace\Importers;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Ecommerce\Facades\EcommerceHelper;
use Botble\Ecommerce\Importers\ProductImporter as BaseProductImporter;
use Botble\Ecommerce\Models\Customer;
use Botble\Ecommerce\Models\Product;
use Botble\Marketplace\Exceptions\ProductLimitExceededException;
use Botble\Marketplace\Facades\MarketplaceHelper;
use Botble\Marketplace\Services\VendorSubscriptionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ProductImporter extends BaseProductImporter
{
    public function getLayout(): string
    {
        return MarketplaceHelper::viewPath('vendor-dashboard.layouts.master');
    }

    public function getValidateUrl(): string
    {
        return route('marketplace.vendor.import.products.validate');
    }

    public function getImportUrl(): string
    {
        return route('marketplace.vendor.import.products.store');
    }

    public function getDownloadExampleUrl(): ?string
    {
        return route('marketplace.vendor.import.products.download-example');
    }

    public function getExportUrl(): ?string
    {
        return route('marketplace.vendor.export.products.index');
    }

    protected function getProductQuery(): Builder
    {
        $customer = auth('customer')->user();

        $query = parent::getProductQuery()
            ->where('created_by_id', $customer?->getKey())
            ->where('created_by_type', Customer::class);

        if ($customer && $customer->store?->id) {
            $query->where('store_id', $customer->store->id);
        } else {
            $query->where('id', 0);
        }

        return $query;
    }

    /**
     * Import row by row so one over-quota product does not abandon the rest of the chunk.
     *
     * The base importer hands the whole chunk to a single handle() call, and
     * DataSynchronize's controller catches at the chunk level — so an exception thrown on
     * row 3 of 100 would silently drop the other 97. Splitting the loop here turns a
     * quota rejection into an ordinary per-row failure the vendor can see and act on.
     */
    public function handle(array $data): int
    {
        // Nothing to guard against outside subscription mode; keep the fast path intact.
        if (! MarketplaceHelper::hasVendorSubscriptions()) {
            return parent::handle($data);
        }

        $vendor = auth('customer')->user();

        foreach ($data as $index => $row) {
            try {
                // Checked before delegating, not only in the observer: the base importer
                // downloads and stores every image URL in the row before it ever reaches
                // the save that trips the guard. Without this, a vendor who is provably
                // over quota still makes the server fetch and keep their media.
                if ($vendor && ($row['import_type'] ?? 'product') === 'product'
                    && ! $this->subscriptions()->canCreateProduct($vendor)) {
                    throw new ProductLimitExceededException();
                }

                parent::handle([$row]);
            } catch (ProductLimitExceededException $exception) {
                // array_filter upstream preserves keys, so $index is the same chunk-relative
                // row number the base importer reports elsewhere.
                $this->onFailure(
                    $index + 1,
                    'name',
                    [$exception->getMessage()],
                    [$row['name'] ?? '']
                );
            }
        }

        return $this->successes()->count();
    }

    /**
     * Resolved per call rather than injected: the importer is constructed by the framework
     * with no container arguments.
     */
    protected function subscriptions(): VendorSubscriptionService
    {
        return app(VendorSubscriptionService::class);
    }

    protected function assignProductData(Request $request, Product $product): Product
    {
        $product->status = MarketplaceHelper::getSetting('enable_product_approval', true)
            ? BaseStatusEnum::PENDING
            : BaseStatusEnum::PUBLISHED;

        if (EcommerceHelper::isEnabledSupportDigitalProducts() && $request->input('product_type')) {
            $product->product_type = $request->input('product_type');
        }

        $product->store_id = auth('customer')->user()->store?->id;
        $product->created_by_id = auth('customer')->id();
        $product->created_by_type = Customer::class;

        return $product;
    }

    public function getUploadUrl(): string
    {
        return route('marketplace.vendor.import.data-synchronize.upload');
    }
}
