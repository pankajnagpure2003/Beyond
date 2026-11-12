<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('mp_vendor_subscription_notifications')) {
            return;
        }

        Schema::create('mp_vendor_subscription_notifications', function (Blueprint $table): void {
            $table->id();
            // No separate index: vendor_subscription_id is the leftmost column of the
            // unique key below, which already serves lookups by subscription.
            $table->foreignId('vendor_subscription_id');
            $table->string('type', 60);
            // What makes this notification unique inside its subscription: the reminder
            // window for an expiry warning, the period end date for a renewal failure.
            // Defaults to '' rather than nullable — NULL never collides in a unique index,
            // which would defeat the whole mechanism.
            $table->string('dedupe_key', 100)->default('');
            // Written by the claim, before the mail is handed over.
            $table->timestamp('claimed_at');
            // Stamped once the mail has been handed to the mailer — not proof of delivery,
            // since transport errors are swallowed by design. A claimed row with a null
            // sent_at is one lost to a hard crash between claim and hand-off.
            $table->timestamp('sent_at')->nullable();

            // Named explicitly: the table name is 36 characters, so Laravel's generated
            // name for this composite comes to 82, over MySQL's 64-char identifier limit.
            $table->unique(
                ['vendor_subscription_id', 'type', 'dedupe_key'],
                'mp_vendor_sub_notifications_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mp_vendor_subscription_notifications');
    }
};
