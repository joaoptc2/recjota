<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 7: índices para as consultas que o cron faz a cada minuto e para as
 * telas mais pesadas. Cada um corresponde a um scope ou a uma listagem real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            // posts:dispatch-due → scopeRetryDue / scopeStuckPublishing
            $table->index(['status', 'next_attempt_at'], 'posts_retry_due_idx');
            // instagram:check-containers → scopeAwaitingContainer
            $table->index(['status', 'container_next_check_at'], 'posts_container_check_idx');
            // métricas e relatórios → posts publicados do cliente no período
            $table->index(['client_id', 'status', 'published_at'], 'posts_client_published_idx');
            // metrics:sync-posts → publicados recentes com mídia
            $table->index(['status', 'published_at'], 'posts_status_published_idx');
        });

        Schema::table('media_assets', function (Blueprint $table): void {
            // ImportCloudFile → já vinculado?
            $table->index(['external_account_id', 'external_file_id'], 'media_assets_external_idx');
        });

        Schema::table('cloud_connections', function (Blueprint $table): void {
            $table->index(['status', 'provider'], 'cloud_connections_status_idx');
        });

        Schema::table('publish_logs', function (Blueprint $table): void {
            // Retenção (model:prune) e histórico por conta
            $table->index('created_at', 'publish_logs_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropIndex('posts_retry_due_idx');
            $table->dropIndex('posts_container_check_idx');
            $table->dropIndex('posts_client_published_idx');
            $table->dropIndex('posts_status_published_idx');
        });

        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropIndex('media_assets_external_idx');
        });

        Schema::table('cloud_connections', function (Blueprint $table): void {
            $table->dropIndex('cloud_connections_status_idx');
        });

        Schema::table('publish_logs', function (Blueprint $table): void {
            $table->dropIndex('publish_logs_created_idx');
        });
    }
};
