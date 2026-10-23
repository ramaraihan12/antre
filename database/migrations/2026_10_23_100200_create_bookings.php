<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('customer_name');
            $table->string('customer_phone', 32);
            // Stored in UTC. The branch timezone decides which local day it belongs to.
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 32)->default('pending_payment');
            $table->unsignedInteger('deposit_amount');
            // The id Midtrans sees. Unique, so a notification always maps to one booking.
            $table->string('order_id')->unique();
            $table->string('snap_token')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamps();

            $table->index(['service_id', 'starts_at', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('payment_notifications', function (Blueprint $table) {
            $table->id();
            // Hash of the fields that identify one notification. A repeat of it is a no-op.
            $table->string('dedupe_key', 64)->unique();
            $table->string('order_id')->index();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->string('transaction_status');
            $table->string('status_code', 8);
            $table->string('gross_amount');
            $table->json('payload');
            // What the state machine did with it: applied, ignored_*, late_*, unknown_order.
            $table->string('outcome', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_notifications');
        Schema::dropIfExists('bookings');
    }
};
