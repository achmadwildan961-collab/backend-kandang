<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('coop_id')->constrained()->cascadeOnDelete();
            $t->foreignId('device_id')->constrained()->cascadeOnDelete();
            $t->uuid('boot_id');
            $t->unsignedBigInteger('last_sequence')->default(0);
            $t->boolean('current')->default(true);
            $t->timestamps();
            $t->unique(['device_id', 'boot_id']);
        });
        Schema::create('integration_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('coop_id')->constrained()->cascadeOnDelete();
            $t->foreignId('device_id')->constrained()->cascadeOnDelete();
            $t->string('domain', 32);
            $t->unsignedInteger('config_version');
            $t->char('message_id', 64)->unique();
            $t->char('payload_hash', 64);
            $t->json('payload');
            $t->string('status', 24)->default('blocked');
            $t->string('reason')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable()->index();
            $t->uuid('lease_token')->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->uuid('boot_id')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('published_at')->nullable();
            $t->timestamp('acknowledged_at')->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->timestamps();
            $t->unique(['coop_id', 'device_id', 'domain', 'config_version'], 'integration_target_version');
        });
        Schema::create('integration_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_id')->constrained()->cascadeOnDelete();
            $t->uuid('message_id');
            $t->char('payload_hash', 64);
            $t->timestamps();
            $t->unique(['device_id', 'message_id']);
        });
        Schema::create('actuator_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('coop_id')->constrained()->cascadeOnDelete();
            $t->foreignId('device_id')->unique()->constrained()->cascadeOnDelete();
            $t->uuid('boot_id');
            $t->unsignedBigInteger('sequence');
            $t->json('states');
            $t->string('feedback_kind', 32);
            $t->timestamp('reported_at');
            $t->timestamps();
        });
        Schema::create('actuator_command_drafts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('coop_id')->constrained()->cascadeOnDelete();
            $t->foreignId('device_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->uuid('request_id');
            $t->char('payload_hash', 64);
            $t->json('payload');
            $t->string('status')->default('blocked');
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->unique(['user_id', 'request_id']);
        });
        Schema::create('push_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->uuid('installation_id');
            $t->string('platform', 16);
            $t->text('token'); // Encrypted at rest; never returned through API.
            $t->char('token_hash', 64)->unique();
            $t->boolean('active')->default(true);
            $t->timestamp('last_seen_at');
            $t->timestamps();
            $t->unique(['user_id', 'installation_id']);
        });
        Schema::create('push_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('notification_id')->constrained()->cascadeOnDelete();
            $t->foreignId('push_token_id')->constrained()->cascadeOnDelete();
            $t->char('token_hash', 64);
            $t->char('message_id', 64)->unique();
            $t->string('status')->default('blocked');
            $t->string('reason')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable()->index();
            $t->uuid('lease_token')->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->timestamp('expires_at');
            $t->string('provider_receipt')->nullable();
            $t->timestamps();
            $t->unique(['notification_id', 'push_token_id']);
        });
    }

    public function down(): void
    {
        $tables = ['push_deliveries', 'push_tokens', 'actuator_command_drafts', 'actuator_reports', 'integration_receipts', 'integration_deliveries', 'integration_sessions'];
        foreach ($tables as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Integration data exists; use a reviewed forward migration.');
            }
        }
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
};
