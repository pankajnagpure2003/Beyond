<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('ec_products') || Schema::hasColumn('ec_products', 'unpublished_by_subscription_at')) {
            return;
        }

        Schema::table('ec_products', function (Blueprint $table): void {
            // Stamped when a subscription expires so the exact same products can be
            // republished on renewal, without touching products the vendor drafted themselves.
            $table->timestamp('unpublished_by_subscription_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('ec_products', 'unpublished_by_subscription_at')) {
            return;
        }

        Schema::table('ec_products', function (Blueprint $table): void {
            $table->dropColumn('unpublished_by_subscription_at');
        });
    }
};
