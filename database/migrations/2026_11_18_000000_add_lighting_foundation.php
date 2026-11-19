<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TYPES = ['indoor_temperature', 'outdoor_temperature', 'indoor_humidity', 'outdoor_humidity', 'gas'];

    public function up(): void
    {
        Schema::table('sensor_readings', function (Blueprint $table) {
            $table->enum('sensor_type', [...self::TYPES, 'outdoor_light'])->change();
            $table->string('measurement_version', 64)->nullable();
        });
        Schema::create('coop_lighting_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coop_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('lighting_mode', ['automatic', 'manual'])->nullable();
            $table->decimal('dark_threshold', 10, 2)->nullable();
            $table->decimal('bright_threshold', 10, 2)->nullable();
            $table->string('threshold_unit', 16)->nullable();
            $table->string('calibration_version', 64)->nullable();
            $table->string('measurement_version', 64)->nullable();
            $table->unsignedInteger('config_version')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('lighting_configuration_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coop_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('config_version');
            $table->json('payload');
            // Durable intent only: not eligible for publish until the device/ACK contract is approved.
            $table->string('status')->default('blocked');
            $table->string('blocked_reason')->default('firmware_contract_unapproved');
            $table->timestamps();
            $table->unique(['coop_id', 'config_version']);
        });
    }

    public function down(): void
    {
        // Refuse a rollback which would erase real readings or saved intent.
        if (DB::table('sensor_readings')->where('sensor_type', 'outdoor_light')->exists()
            || DB::table('coop_lighting_settings')->exists()
            || DB::table('lighting_configuration_outbox')->exists()) {
            throw new RuntimeException('Lighting data exists; export and review a forward migration instead.');
        }
        Schema::dropIfExists('lighting_configuration_outbox');
        Schema::dropIfExists('coop_lighting_settings');
        Schema::table('sensor_readings', function (Blueprint $table) {
            $table->dropColumn('measurement_version');
            $table->enum('sensor_type', self::TYPES)->change();
        });
    }
};
