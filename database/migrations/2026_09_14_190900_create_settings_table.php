<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->json('value')->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['key', 'client_id']);
        });

        // Heartbeat do cron e demais sinais de saúde (Seção 8.3). Sem isto um
        // cron quebrado passa dias despercebido.
        Schema::create('system_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('last_duration_ms')->nullable();
            $table->string('last_status', 16)->default('ok');
            $table->text('last_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_heartbeats');
        Schema::dropIfExists('settings');
    }
};
