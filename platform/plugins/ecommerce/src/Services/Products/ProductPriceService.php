<?php

namespace Botble\Ecommerce\Services\Products;

use Botble\Ecommerce\Models\Product;
use Illuminate\Support\Facades\Pipeline;

class ProductPriceService
{
    /**
     * Reductions every visitor sees on the product regardless of context.
     */
    protected array $publicPriceHandlers = [
        ProductSalePriceService::class,
        ProductFlashSalePriceService::class,
        ProductDiscountPriceService::class,
    ];

    /**
     * Reductions that only exist in a given context (what else is being viewed
     * or bought), so they must not make the product render as "on sale".
     */
    protected array $contextualPriceHandlers = [
        ProductCrossSalePriceService::class,
        ProductUpSalePriceService::class,
    ];

    public function __construct(
        protected float $finalPrice = 0,
        protected ?Product $product = null
    ) {
    }

    public function getPrice(Product $product): float
    {
        $product->setFinalPrice($product->getConvertedPrice());

        $product = $this->applyPriceHandlers($product);

        return (float) apply_filters('ecommerce_product_final_price', $product->getFinalPrice(), $product);
    }

    /**
     * Price after publicly visible reductions only (sale price, flash sale,
     * promotion). Used to decide whether a product is on sale and by how much.
     */
    public function getPublicPrice(Product $product): float
    {
        $product->setFinalPrice($product->getConvertedPrice());

        $product = Pipeline::send($product)
            ->through($this->publicPriceHandlers)
            ->thenReturn();

        return (float) $product->getFinalPrice();
    }

    public function getOriginalPrice(Product $product): float
    {
        $product->setOriginalPrice($product->getConvertedPrice());

        $product = $this->applyPriceHandlers($product);

        return (float) apply_filters('ecommerce_product_original_price', $product->getOriginalPrice(), $product);
    }

    /**
     * The service is a singleton and price accessors are re-entrant: a handler or filter
     * may read another product's price (bundle pricing, cross-sell), which would clobber
     * shared state. Keep the product in a local so nested calls stay isolated.
     *
     * Register additional handlers through the `ecommerce_product_price_handlers` filter.
     * Each handler must extend ProductPriceHandlerService.
     */
    protected function applyPriceHandlers(Product $product): Product
    {
        $handlers = apply_filters(
            'ecommerce_product_price_handlers',
            [...$this->publicPriceHandlers, ...$this->contextualPriceHandlers],
            $product
        );

        return Pipeline::send($product)
            ->through($handlers)
            ->thenReturn();
    }
}
