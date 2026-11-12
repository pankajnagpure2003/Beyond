<?php

namespace Botble\Setting\Tests\Feature;

use Botble\Setting\Models\Setting;
use Botble\Setting\Supports\DatabaseSettingStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DatabaseSettingStoreDeleteTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * CleanDatabaseService wipes settings with forceDelete(except: [...]) and no
     * keys. The except list used to be compared against the (empty) key list, so
     * the query became `whereNotIn('key', [])` — `1 = 1` — and the theme,
     * activated plugins and licence it meant to keep were deleted with the rest.
     */
    public function test_delete_with_only_an_except_list_keeps_the_excepted_keys(): void
    {
        $store = new DatabaseSettingStore();
        $store->forceSet([
            'test_clean_keep_theme' => 'kept',
            'test_clean_keep_plugins' => 'kept',
            'test_clean_drop_me' => 'dropped',
        ])->save();

        $store->forceDelete(except: ['test_clean_keep_theme', 'test_clean_keep_plugins']);

        $remaining = Setting::query()->pluck('key')->all();

        $this->assertContains('test_clean_keep_theme', $remaining);
        $this->assertContains('test_clean_keep_plugins', $remaining);
        $this->assertNotContains('test_clean_drop_me', $remaining);
    }

    public function test_delete_with_keys_removes_only_those_keys(): void
    {
        $store = new DatabaseSettingStore();
        $store->forceSet([
            'test_delete_target' => 'x',
            'test_delete_bystander' => 'y',
        ])->save();

        $store->forceDelete(['test_delete_target']);

        $remaining = Setting::query()->pluck('key')->all();

        $this->assertNotContains('test_delete_target', $remaining);
        $this->assertContains('test_delete_bystander', $remaining);
    }
}
