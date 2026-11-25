<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sensor_readings', function (Blueprint $table) {
            // Scoped with coop/device/time; old rows retain explicitly legacy provenance.
            $table->char('batch_key', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('sensor_readings')->whereNotNull('batch_key')->exists()) {
            throw new RuntimeException('Accepted batch identities exist; use a reviewed forward migration.');
        }
        Schema::table('sensor_readings', fn (Blueprint $table) => $table->dropColumn('batch_key'));
    }
};
