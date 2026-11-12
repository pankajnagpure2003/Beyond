<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('mp_vendor_subscription_logs')) {
            return;
        }

        Schema::create('mp_vendor_subscription_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_subscription_id')->index();
            $table->string('type', 60);
            $table->json('data')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mp_vendor_subscription_logs');
    }
};
