<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Quando um post é composto para várias contas, os irmãos apontam
            // para o primeiro post gerado.
            $table->foreignId('sibling_group_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->string('type', 20);
            $table->text('caption')->nullable();
            $table->text('first_comment')->nullable();
            // SEMPRE em UTC (R2). A conversão para o fuso do cliente é de exibição.
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('status', 24)->default('draft');
            $table->string('approval_status', 24)->default('pending');
            $table->string('external_post_id')->nullable();
            $table->string('external_permalink')->nullable();
            $table->string('external_container_id')->nullable();
            $table->unsignedTinyInteger('publish_attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->boolean('last_error_is_permanent')->default(false);
            // Lock pessimista contra publicação dupla quando dois crons se
            // sobrepõem (Seção 6.7).
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('current_version')->default(1);
            $table->unsignedInteger('approved_version')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'scheduled_at']);
            $table->index(['status', 'scheduled_at']);
            $table->index(['social_account_id', 'published_at']);
            $table->index(['client_id', 'approval_status']);
        });

        Schema::create('post_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(0);
            $table->string('alt_text')->nullable();
            $table->unsignedInteger('thumbnail_offset_ms')->nullable();
            $table->string('cover_path')->nullable();
            $table->timestamps();

            $table->unique(['post_id', 'position']);
        });

        // Histórico imutável: toda edição após o envio para aprovação gera versão.
        Schema::create('post_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('change_summary')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['post_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_versions');
        Schema::dropIfExists('post_media');
        Schema::dropIfExists('posts');
    }
};
