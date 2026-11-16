<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A constant default, so Postgres adds the column without rewriting the table.
        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedBigInteger('display_version')->default(0);
        });

        // TVs that are already open still run the old script, which numbered snapshots with the
        // clock in milliseconds and ignores anything lower. Start the counter above that so they
        // keep following the display until they are reloaded. It only goes up from here.
        DB::table('branches')->update(['display_version' => (int) floor(microtime(true) * 1000)]);
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('display_version');
        });
    }
};
