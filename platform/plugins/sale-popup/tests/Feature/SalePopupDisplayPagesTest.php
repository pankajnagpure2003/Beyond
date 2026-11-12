<?php

namespace Botble\SalePopup\Tests\Feature;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Ecommerce\Enums\CustomerStatusEnum;
use Botble\Ecommerce\Models\Customer;
use Botble\Ecommerce\Models\Product;
use Botble\Setting\Facades\Setting;
use Botble\Slug\Facades\SlugHelper;
use Botble\Slug\Models\Slug;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Product detail pages are served by the catch-all `public.single` route, so matching the
 * saved `public.product` value against the current route name never succeeded and the popup
 * silently stayed hidden there. These tests lock in that the popup follows the selected pages.
 *
 * Plugin namespaces are registered at runtime by the plugin loader rather than in composer's
 * autoload map, so the suite skips (rather than errors) when the plugins are deactivated.
 */
class SalePopupDisplayPagesTest extends TestCase
{
    use DatabaseTransactions;

    private const MARKER = 'js-sale-popup-container';

    private string $productUrl;

    private string $productSlug = 'sale-popup-test-product';

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists('Botble\\SalePopup\\Support\\SalePopupHelper') || ! class_exists(Product::class)) {
            $this->markTestSkipped('sale-popup or ecommerce plugin is not active.');
        }

        Setting::set('sale_popup_enabled', 1);

        $this->productUrl = $this->createProduct();
    }

    /**
     * Built here rather than read from seed data: the Platform suite runs a `RefreshDatabase`
     * test that wipes seeded products for everything after it.
     */
    private function createProduct(): string
    {
        $product = Product::query()->create([
            'name' => 'Sale popup test product',
            'status' => BaseStatusEnum::PUBLISHED,
            'price' => 100,
            'quantity' => 10,
            'with_storehouse_management' => 0,
            'stock_status' => 'in_stock',
            'is_variation' => 0,
        ]);

        Slug::query()->create([
            'key' => $this->productSlug,
            'reference_type' => Product::class,
            'reference_id' => $product->getKey(),
            'prefix' => SlugHelper::getPrefix(Product::class),
        ]);

        return $product->refresh()->url;
    }

    private function selectPages(array $pages): void
    {
        Setting::set('sale_popup_display_pages', json_encode($pages));
    }

    public function testPopupRendersOnProductDetailWhenProductDetailIsSelected(): void
    {
        $this->selectPages(['public.product']);

        $this->get($this->productUrl)
            ->assertOk()
            ->assertSee(self::MARKER, false);
    }

    public function testPopupIsHiddenOnProductDetailWhenOnlyHomepageIsSelected(): void
    {
        $this->selectPages(['public.index']);

        $this->get($this->productUrl)
            ->assertOk()
            ->assertDontSee(self::MARKER, false);
    }

    public function testPopupIsHiddenOnHomepageWhenOnlyProductDetailIsSelected(): void
    {
        $this->selectPages(['public.product']);

        $this->get('/')
            ->assertOk()
            ->assertDontSee(self::MARKER, false);
    }

    public function testPopupIsHiddenOnProductListingWhenOnlyProductDetailIsSelected(): void
    {
        $this->selectPages(['public.product']);

        $this->get(route('public.products'))
            ->assertOk()
            ->assertDontSee(self::MARKER, false);
    }

    /**
     * The write-a-review page fires the same `BASE_ACTION_PUBLIC_RENDER_SINGLE` action with the
     * product screen name, so it must not be mistaken for the product detail page.
     */
    public function testPopupIsHiddenOnProductReviewPageWhenOnlyProductDetailIsSelected(): void
    {
        Setting::set('ecommerce_review_enabled', 1);
        Setting::set('ecommerce_only_allow_customers_purchased_to_review', 0);

        $customer = Customer::query()->create([
            'name' => 'Sale popup tester',
            'email' => 'sale-popup-tester@example.test',
            'password' => 'password',
            'status' => CustomerStatusEnum::ACTIVATED,
        ]);

        $this->selectPages(['public.product']);

        $this->actingAs($customer, 'customer')
            ->get(route('public.product.review', $this->productSlug))
            ->assertOk()
            ->assertDontSee(self::MARKER, false);
    }
}
