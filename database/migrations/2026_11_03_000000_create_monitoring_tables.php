<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coops', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('location')->nullable();
            $t->text('description')->nullable();
            $t->timestamps();
        });
        Schema::create('coop_user', function (Blueprint $t) {
            $t->foreignId('coop_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->primary(['coop_id', 'user_id']);
            $t->index('user_id');
        });
        Schema::create('devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('coop_id')->constrained()->cascadeOnDelete();
            $t->string('device_identifier', 100)->unique();
            $t->string('name');
            $t->string('type', 50);
            $t->enum('connection_status', ['online', 'offline', 'unknown'])->default('unknown');
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
            $t->unique(['id', 'coop_id']);
            $t->index(['coop_id', 'connection_status']);
        });
        Schema::create('sensor_readings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('device_id');
            $t->foreignId('coop_id')->constrained()->cascadeOnDelete();
            $t->foreign(['device_id', 'coop_id'])->references(['id', 'coop_id'])->on('devices')->cascadeOnDelete();
            $t->string('sensor_identifier', 100);
            $t->enum('sensor_type', ['indoor_temperature', 'outdoor_temperature', 'indoor_humidity', 'outdoor_humidity', 'gas']);
            $t->decimal('value', 10, 2);
            $t->string('unit', 16);
            $t->timestamp('recorded_at', 6);
            $t->unique(['coop_id', 'sensor_identifier', 'sensor_type', 'recorded_at'], 'readings_sample_unique');
            $t->index(['coop_id', 'recorded_at']);
            $t->index(['device_id', 'recorded_at']);
        });
        Schema::create('coop_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('coop_id')->unique()->constrained()->cascadeOnDelete();
            $t->decimal('minimum_outdoor_temperature', 5, 2)->default(25);
            $t->decimal('maximum_indoor_temperature', 5, 2)->default(30);
            $t->unsignedInteger('gas_threshold')->default(1500);
            $t->json('notification_preferences');
            // Internal transition state is persisted to deduplicate evaluations across requests/workers.
            $t->json('alert_state')->nullable();
            $t->timestamps();
        });
        Schema::create('system_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('coop_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('device_id')->nullable();
            $t->foreign(['device_id', 'coop_id'])->references(['id', 'coop_id'])->on('devices');
            $t->string('event_type', 64);
            $t->enum('severity', ['info', 'warning', 'critical']);
            $t->text('description');
            $t->json('metadata')->nullable();
            $t->timestamp('occurred_at', 6);
            $t->index(['coop_id', 'occurred_at']);
            $t->index(['coop_id', 'event_type', 'occurred_at']);
        });
        Schema::create('notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('coop_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('system_event_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 64);
            $t->string('title');
            $t->text('message');
            $t->enum('severity', ['info', 'warning', 'critical']);
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'system_event_id']);
            $t->index(['user_id', 'read_at', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['notifications', 'system_events', 'coop_settings', 'sensor_readings', 'devices', 'coop_user', 'coops'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
