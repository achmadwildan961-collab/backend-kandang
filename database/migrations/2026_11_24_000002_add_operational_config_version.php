<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coop_settings', fn (Blueprint $table) => $table->unsignedBigInteger('config_version')->default(1));
    }

    public function down(): void
    {
        if (DB::table('coop_settings')->where('config_version', '>', 1)->exists()) {
            throw new RuntimeException('Versioned settings exist; use a reviewed forward migration.');
        }
        Schema::table('coop_settings', fn (Blueprint $table) => $table->dropColumn('config_version'));
    }
};
