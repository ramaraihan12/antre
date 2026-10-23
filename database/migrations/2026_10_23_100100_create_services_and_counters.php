<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 1);
            $table->unsignedSmallInteger('avg_duration_minutes')->default(15);
            // Whole rupiah. Zero means the service cannot be booked online.
            $table->unsignedInteger('deposit_amount')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'code']);
        });

        Schema::create('counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->timestamps();

            $table->unique(['branch_id', 'number']);
        });

        Schema::create('counter_service', function (Blueprint $table) {
            $table->foreignId('counter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();

            $table->primary(['counter_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counter_service');
        Schema::dropIfExists('counters');
        Schema::dropIfExists('services');
    }
};
