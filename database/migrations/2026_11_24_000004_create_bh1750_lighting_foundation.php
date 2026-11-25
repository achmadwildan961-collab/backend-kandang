<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Separate domain/version namespace: never backfill or reinterpret legacy ADC thresholds.
        Schema::create('coop_lighting_lux_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coop_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('lighting_mode', ['auto', 'manual'])->nullable();
            $table->decimal('min_lux', 10, 2)->nullable();
            $table->decimal('max_lux', 10, 2)->nullable();
            $table->string('threshold_unit', 16)->default('lux');
            $table->string('measurement_version', 64)->nullable();
            $table->unsignedInteger('config_version')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('lighting_lux_configuration_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coop_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('config_version');
            $table->char('idempotency_key', 64)->unique();
            $table->json('payload');
            $table->string('status')->default('blocked');
            $table->string('blocked_reason')->default('firmware_and_feedback_unverified');
            $table->timestamps();
            $table->unique(['coop_id', 'config_version']);
        });
    }

    public function down(): void
    {
        if (DB::table('coop_lighting_lux_settings')->exists() || DB::table('lighting_lux_configuration_outbox')->exists()) {
            throw new RuntimeException('BH1750 lighting configuration exists; use a reviewed forward migration.');
        }
        Schema::dropIfExists('lighting_lux_configuration_outbox');
        Schema::dropIfExists('coop_lighting_lux_settings');
    }
};
