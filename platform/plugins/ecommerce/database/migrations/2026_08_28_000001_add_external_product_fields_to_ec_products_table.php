<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ec_products', function (Blueprint $table): void {
            if (! Schema::hasColumn('ec_products', 'is_affiliate')) {
                $table->boolean('is_affiliate')->default(false)->after('is_featured');
            }

            if (! Schema::hasColumn('ec_products', 'external_url')) {
                $table->string('external_url', 400)->nullable()->after('is_affiliate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ec_products', function (Blueprint $table): void {
            $table->dropColumn(['is_affiliate', 'external_url']);
        });
    }
};
