<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Public URLs use the slug, so it is unique across all businesses.
            $table->string('slug')->unique();
            $table->string('city')->nullable();
            $table->string('timezone')->default('Asia/Jakarta');
            // {"1": ["09:00", "17:00"], ..., "0": null}, keyed by weekday number (0 is Sunday).
            $table->json('opening_hours');
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('business_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            // Null means the user can work at every branch of the business.
            $table->foreignId('branch_id')->nullable()->after('business_id')->constrained()->nullOnDelete();
            $table->string('role')->default('staff')->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('business_id');
            $table->dropColumn('role');
        });
        Schema::dropIfExists('branches');
        Schema::dropIfExists('businesses');
    }
};
