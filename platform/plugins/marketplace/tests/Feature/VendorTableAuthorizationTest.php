<?php

namespace Botble\Marketplace\Tests\Feature;

use Botble\ACL\Models\User;
use Botble\ACL\Services\ActivateUserService;
use Botble\ACL\Tables\UserTable;
use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Models\Brand;
use Botble\Ecommerce\Models\Customer;
use Botble\Ecommerce\Models\Discount;
use Botble\Ecommerce\Models\Product;
use Botble\Ecommerce\Tables\BrandTable;
use Botble\Ecommerce\Tables\ProductTable;
use Botble\Marketplace\Models\Store;
use Botble\Marketplace\Tables\DiscountTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

/**
 * The vendor panel mounts the shared core table routes (bulk-actions, bulk-changes,
 * filters) with `'permission' => false`, and those controllers instantiate whatever
 * TableAbstract subclass the request names. Before EnsureVendorTableRequest /
 * ForVendor::scopeBulkActionIds() / TableAbstract::hasPermission()'s admin-guard
 * check, a vendor could name an admin-only table (or another vendor's row on a
 * vendor table) and have it deleted or bulk-changed with no ownership check.
 */
class VendorTableAuthorizationTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function createAdminUser(): User
    {
        Schema::disableForeignKeyConstraints();
        User::query()->truncate();

        $user = new User();
        $user->forceFill([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'admin@test.com',
            'username' => 'admin',
            'password' => bcrypt('password'),
            'super_user' => 1,
            'manage_supers' => 1,
        ]);
        $user->save();

        app(ActivateUserService::class)->activate($user);

        return $user;
    }

    protected function createVendor(string $label): Customer
    {
        $customer = new Customer();
        $customer->forceFill([
            'name' => $label,
            'email' => strtolower(str_replace(' ', '-', $label)) . '-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'is_vendor' => 1,
            'vendor_verified_at' => now(),
        ]);
        $customer->save();

        $store = Store::query()->create([
            'name' => $label . ' Store',
            'email' => strtolower(str_replace(' ', '-', $label)) . '-store-' . uniqid() . '@example.com',
            'customer_id' => $customer->id,
            'status' => 'published',
        ]);

        $customer->store_id = $store->id;

        return $customer->refresh();
    }

    protected function createProduct(?int $storeId): Product
    {
        $product = new Product();
        $product->forceFill([
            'name' => 'Product for ' . uniqid(),
            'price' => 10,
            'status' => BaseStatusEnum::PUBLISHED,
            'store_id' => $storeId,
        ]);
        $product->save();

        return $product;
    }

    protected function createDiscount(?int $storeId): Discount
    {
        $discount = new Discount();
        $discount->forceFill([
            'title' => 'Discount for ' . uniqid(),
            'code' => 'CODE' . uniqid(),
            'type' => 'coupon',
            'store_id' => $storeId,
        ]);
        $discount->save();

        return $discount;
    }

    protected function bulkDelete(Customer $vendor, string $table, int|string $id): TestResponse
    {
        return $this->actingAs($vendor, 'customer')->postJson('/vendor/tables/bulk-actions', [
            'bulk_action' => 1,
            'bulk_action_table' => $table,
            'bulk_action_target' => 'Botble\Table\BulkActions\DeleteBulkAction',
            'ids' => [(string) $id],
        ]);
    }

    public function test_vendor_cannot_bulk_delete_an_admin_brand(): void
    {
        $vendor = $this->createVendor('Vendor A');
        $brand = Brand::query()->create(['name' => 'Admin Brand', 'status' => BaseStatusEnum::PUBLISHED]);

        $this->bulkDelete($vendor, BrandTable::class, $brand->id)
            ->assertForbidden();

        $this->assertDatabaseHas('ec_brands', ['id' => $brand->id]);
    }

    public function test_vendor_cannot_bulk_delete_an_admin_product(): void
    {
        $vendor = $this->createVendor('Vendor B');
        $product = $this->createProduct(null);

        $this->bulkDelete($vendor, ProductTable::class, $product->id)
            ->assertForbidden();

        $this->assertDatabaseHas('ec_products', ['id' => $product->id]);
    }

    public function test_vendor_cannot_bulk_delete_a_core_admin_user(): void
    {
        $admin = $this->createAdminUser();
        $vendor = $this->createVendor('Vendor C');

        $this->bulkDelete($vendor, UserTable::class, $admin->id)
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_vendor_cannot_reach_bulk_change_data_for_an_admin_table(): void
    {
        $vendor = $this->createVendor('Vendor D');

        $this->actingAs($vendor, 'customer')
            ->getJson('/vendor/tables/bulk-changes/data?' . http_build_query([
                'class' => BrandTable::class,
            ]))
            ->assertForbidden();
    }

    public function test_vendor_cannot_reach_filters_for_an_admin_table(): void
    {
        $vendor = $this->createVendor('Vendor E');

        $this->actingAs($vendor, 'customer')
            ->getJson('/vendor/tables/filters?' . http_build_query([
                'class' => BrandTable::class,
            ]))
            ->assertForbidden();
    }

    public function test_vendor_cannot_bulk_delete_another_vendors_product(): void
    {
        $vendorA = $this->createVendor('Vendor F');
        $vendorB = $this->createVendor('Vendor G');
        $product = $this->createProduct($vendorB->store->id);

        $this->bulkDelete($vendorA, \Botble\Marketplace\Tables\ProductTable::class, $product->id)
            ->assertOk()
            ->assertJsonFragment(['error' => true]);

        $this->assertDatabaseHas('ec_products', ['id' => $product->id]);
    }

    public function test_vendor_cannot_bulk_delete_another_vendors_discount(): void
    {
        $vendorA = $this->createVendor('Vendor H');
        $vendorB = $this->createVendor('Vendor I');
        $discount = $this->createDiscount($vendorB->store->id);

        $this->bulkDelete($vendorA, DiscountTable::class, $discount->id)
            ->assertOk()
            ->assertJsonFragment(['error' => true]);

        $this->assertDatabaseHas('ec_discounts', ['id' => $discount->id]);
    }

    public function test_vendor_cannot_bulk_change_another_vendors_product(): void
    {
        $vendorA = $this->createVendor('Vendor J');
        $vendorB = $this->createVendor('Vendor K');
        $product = $this->createProduct($vendorB->store->id);

        $this->actingAs($vendorA, 'customer')->postJson('/vendor/tables/bulk-changes/save', [
            'ids' => [(string) $product->id],
            'key' => 'name',
            'value' => 'Renamed by attacker',
            'class' => \Botble\Marketplace\Tables\ProductTable::class,
        ])->assertOk();

        $this->assertDatabaseHas('ec_products', ['id' => $product->id, 'name' => $product->name]);
        $this->assertDatabaseMissing('ec_products', ['id' => $product->id, 'name' => 'Renamed by attacker']);
    }

    public function test_vendor_can_bulk_delete_own_product(): void
    {
        $vendor = $this->createVendor('Vendor L');
        $product = $this->createProduct($vendor->store->id);

        $this->bulkDelete($vendor, \Botble\Marketplace\Tables\ProductTable::class, $product->id)
            ->assertOk()
            ->assertJsonFragment(['error' => false]);

        $this->assertDatabaseMissing('ec_products', ['id' => $product->id]);
    }

    public function test_vendor_can_bulk_change_own_product(): void
    {
        $vendor = $this->createVendor('Vendor M');
        $product = $this->createProduct($vendor->store->id);

        $this->actingAs($vendor, 'customer')->postJson('/vendor/tables/bulk-changes/save', [
            'ids' => [(string) $product->id],
            'key' => 'name',
            'value' => 'Renamed by owner',
            'class' => \Botble\Marketplace\Tables\ProductTable::class,
        ])->assertOk()->assertJsonFragment(['error' => false]);

        $this->assertDatabaseHas('ec_products', ['id' => $product->id, 'name' => 'Renamed by owner']);
    }

    public function test_admin_bulk_delete_still_works_for_the_admin_brand_table(): void
    {
        $admin = $this->createAdminUser();
        $brand = Brand::query()->create(['name' => 'Deletable Admin Brand', 'status' => BaseStatusEnum::PUBLISHED]);

        $this->actingAs($admin, 'web')->postJson(route('table.bulk-action.dispatch'), [
            'bulk_action' => 1,
            'bulk_action_table' => BrandTable::class,
            'bulk_action_target' => 'Botble\Table\BulkActions\DeleteBulkAction',
            'ids' => [(string) $brand->id],
        ])->assertOk()->assertJsonFragment(['error' => false]);

        $this->assertDatabaseMissing('ec_brands', ['id' => $brand->id]);
    }
}
