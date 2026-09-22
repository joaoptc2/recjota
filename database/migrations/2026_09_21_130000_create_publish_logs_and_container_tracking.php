<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motor de publicação (Seção 8): rastreio do container e histórico de
 * tentativas. Toda tentativa, em qualquer etapa, fica registrada com o payload
 * de tokens mascarados — é o que permite explicar ao gestor por que um post
 * falhou sem abrir o log do servidor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Quando o container foi criado: o polling desiste após 5 min.
            $table->timestamp('container_created_at')->nullable()->after('external_container_id');
            // Próxima checagem prevista; a rede de segurança (instagram:check-containers)
            // reenfileira quando o job com delay se perdeu.
            $table->timestamp('container_next_check_at')->nullable()->after('container_created_at');
            // Dados auxiliares da publicação em andamento (filhos do carrossel,
            // id do primeiro comentário). Limpo a cada nova tentativa.
            $table->json('publish_meta')->nullable()->after('container_next_check_at');

            $table->index(['status', 'container_next_check_at']);
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::create('publish_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt')->default(1);
            // container | status | publish | comment | quota (enum PHP PublishStage)
            $table->string('stage', 16);
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->boolean('succeeded')->default(false);
            $table->text('error')->nullable();
            // UTC, como tudo no banco (R2).
            $table->timestamp('created_at')->nullable();

            $table->index(['post_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publish_logs');

        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['status', 'container_next_check_at']);
            $table->dropIndex(['status', 'next_attempt_at']);
            $table->dropColumn(['container_created_at', 'container_next_check_at', 'publish_meta']);
        });
    }
};
