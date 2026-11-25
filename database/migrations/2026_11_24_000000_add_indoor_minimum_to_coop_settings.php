<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coop_settings', function (Blueprint $table) {
            // Deliberately no backfill from the unrelated historical outdoor limit.
            $table->decimal('minimum_indoor_temperature', 5, 2)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('coop_settings')->whereNotNull('minimum_indoor_temperature')->exists()) {
            throw new RuntimeException('Indoor minimum data exists; use a reviewed forward migration.');
        }
        Schema::table('coop_settings', fn (Blueprint $table) => $table->dropColumn('minimum_indoor_temperature'));
    }
};
