<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_configuration_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coop_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('device_id');
            $table->foreign(['device_id', 'coop_id'])->references(['id', 'coop_id'])->on('devices')->cascadeOnDelete();
            $table->unsignedBigInteger('config_version');
            $table->char('idempotency_key', 64)->unique();
            $table->json('payload');
            $table->string('status', 32)->default('blocked');
            $table->string('blocked_reason')->default('firmware_contract_unverified');
            $table->timestamps();
            $table->unique(['coop_id', 'device_id', 'config_version'], 'operational_outbox_target_version_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('operational_configuration_outbox')->exists()) {
            throw new RuntimeException('Configuration intent exists; use a reviewed forward migration.');
        }
        Schema::dropIfExists('operational_configuration_outbox');
    }
};
