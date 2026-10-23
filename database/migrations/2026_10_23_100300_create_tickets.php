<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('counter_id')->nullable()->constrained()->nullOnDelete();
            // The branch's local date, so numbers restart at 1 every day.
            $table->date('queue_date');
            $table->unsignedSmallInteger('number');
            $table->string('code', 8);
            $table->string('status', 16)->default('waiting');
            $table->boolean('is_priority')->default(false);
            $table->unsignedSmallInteger('call_count')->default(0);
            $table->timestamp('called_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'service_id', 'queue_date', 'number']);
            $table->index(['branch_id', 'queue_date', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // The hot query: waiting tickets of a branch in calling order.
            DB::statement(
                "create index tickets_waiting_order on tickets (branch_id, queue_date, is_priority desc, id) where status = 'waiting'"
            );
        }

        Schema::create('ticket_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('counter_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8)->default('call');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['branch_id', 'id']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ticket_id');
        });
        Schema::dropIfExists('ticket_calls');
        Schema::dropIfExists('tickets');
    }
};
