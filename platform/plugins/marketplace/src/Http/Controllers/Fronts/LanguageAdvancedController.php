<?php

namespace Botble\Marketplace\Http\Controllers\Fronts;

use Botble\Base\Models\BaseModel;
use Botble\Ecommerce\Models\Customer;
use Botble\Ecommerce\Models\Product;
use Botble\Ecommerce\Models\SpecificationAttribute;
use Botble\Ecommerce\Models\SpecificationGroup;
use Botble\Ecommerce\Models\SpecificationTable;
use Botble\LanguageAdvanced\Http\Controllers\LanguageAdvancedController as BaseLanguageAdvancedController;
use Botble\LanguageAdvanced\Http\Requests\LanguageAdvancedRequest;
use Botble\LanguageAdvanced\Supports\LanguageAdvancedManager;
use Botble\Marketplace\Forms\ProductForm;
use Botble\Marketplace\Forms\SpecificationAttributeForm;
use Botble\Marketplace\Forms\SpecificationGroupForm;
use Botble\Marketplace\Forms\SpecificationTableForm;
use Botble\Marketplace\Forms\VendorStoreForm;
use Botble\Marketplace\Models\Store;

class LanguageAdvancedController extends BaseLanguageAdvancedController
{
    /**
     * Translatable models the vendor dashboard edits, mapped to the vendor form that renders them.
     * Anything else posted to this route is rejected.
     */
    protected const FORMS = [
        Product::class => ProductForm::class,
        Store::class => VendorStoreForm::class,
        SpecificationGroup::class => SpecificationGroupForm::class,
        SpecificationAttribute::class => SpecificationAttributeForm::class,
        SpecificationTable::class => SpecificationTableForm::class,
    ];

    public function save(int|string $id, LanguageAdvancedRequest $request)
    {
        // The parent controller loads whatever model class the request names. Only allow the
        // models this dashboard edits, and only records owned by the signed-in vendor.
        $record = $this->findOwnedRecord((string) $request->input('model'), $id);

        abort_unless($record, 404);

        // Fall back to the locale being edited when no language meta box posted one,
        // otherwise the parent skips saving the translated slug.
        $language = LanguageAdvancedManager::getTranslationLocale();

        if (! $request->filled('language') && LanguageAdvancedManager::isValidLanguageCode($language)) {
            $request->merge(['language' => $language]);
        }

        $request->merge([
            'model' => $record::class,
            'form' => self::FORMS[$record::class],
            'slug_id' => $record->slugable?->getKey(),
        ]);

        return parent::save($record->getKey(), $request);
    }

    /**
     * Mirrors the ownership checks of the vendor ProductController, SettingController and
     * Specification*Controller, which guard the regular edit pages.
     */
    protected function findOwnedRecord(string $model, int|string $id): ?BaseModel
    {
        /**
         * @var Customer|null $customer
         */
        $customer = auth('customer')->user();

        if (! $customer || ! $customer->is_vendor) {
            return null;
        }

        $storeId = $customer->store?->getKey();

        return match ($model) {
            Product::class => $storeId
                ? Product::query()
                    ->whereKey($id)
                    ->where('is_variation', false)
                    ->where('store_id', $storeId)
                    ->first()
                : null,
            Store::class => $storeId && (string) $id === (string) $storeId ? $customer->store : null,
            SpecificationGroup::class, SpecificationAttribute::class, SpecificationTable::class => $model::query()
                ->whereKey($id)
                ->where([
                    'author_type' => Customer::class,
                    'author_id' => $customer->getKey(),
                ])
                ->first(),
            default => null,
        };
    }
}
