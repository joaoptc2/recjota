<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo de vida do token (Seção 7.1.3) e consentimento LGPD (Seção 10).
 *
 * token_refreshed_at: o Instagram só renova token com pelo menos 24h de idade;
 *   é esta coluna que o tokens:refresh consulta (updated_at muda por qualquer
 *   edição e não serve de idade do token).
 * consent_given_at / consent_given_by: quem autorizou a conexão e quando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->timestamp('token_refreshed_at')->nullable()->after('token_expires_at');
            $table->timestamp('consent_given_at')->nullable()->after('last_synced_at');
            $table->foreignId('consent_given_by')->nullable()->after('consent_given_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consent_given_by');
            $table->dropColumn(['token_refreshed_at', 'consent_given_at']);
        });
    }
};
