<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20);
            $table->string('external_id', 64);
            $table->string('username')->nullable();
            $table->string('display_name')->nullable();
            $table->string('avatar_url')->nullable();
            $table->string('account_type', 16)->default('business');
            // Tokens sempre criptografados em repouso (Seção 10) - text por causa
            // do overhead do cipher do Laravel.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable()->index();
            $table->json('scopes')->nullable();
            $table->string('connection_status', 16)->default('connected')->index();
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'platform']);
            $table->unique(['platform', 'external_id']);
        });

        // Cache local da cota de publicação da plataforma (Seção 7.1.5). Evita
        // "tentar e falhar": consultamos antes de cada publicação.
        Schema::create('publishing_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->timestamp('window_start');
            $table->unsignedSmallInteger('used_count')->default(0);
            $table->unsignedSmallInteger('quota_total')->default(50);
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['social_account_id', 'window_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publishing_quotas');
        Schema::dropIfExists('social_accounts');
    }
};
