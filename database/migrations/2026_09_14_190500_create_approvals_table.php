<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            // A decisão vale para UMA versão específica. Editar depois invalida.
            $table->unsignedInteger('post_version');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('due_at')->nullable()->index();
            $table->string('status', 24)->default('pending')->index();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_by_name')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->string('decided_via', 16)->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->index(['post_id', 'post_version']);
        });

        // Link mágico: caminho primário de aprovação (Seção 6.6). Token guardado
        // como hash; o valor em claro só existe dentro do e-mail enviado.
        Schema::create('approval_links', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('token', 64)->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope', 20)->default('single_post');
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('expires_at')->index();
            $table->unsignedSmallInteger('max_uses')->default(20);
            $table->unsignedSmallInteger('used_count')->default(0);
            $table->string('recipient_email')->nullable();
            $table->string('recipient_name')->nullable();
            // Invalidado quando o post muda de versão (Seção 10).
            $table->unsignedInteger('bound_version')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_links');
        Schema::dropIfExists('approvals');
    }
};
