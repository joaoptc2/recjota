<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5: identidade da conta na nuvem, escopos concedidos, consentimento
 * (LGPD) e ciclo de vida do token, espelhando social_accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cloud_connections', function (Blueprint $table): void {
            $table->string('account_id')->nullable()->after('provider');
            $table->string('account_name')->nullable()->after('account_email');
            $table->json('scopes')->nullable()->after('root_folder_id');
            $table->text('last_error')->nullable()->after('status');
            $table->timestamp('token_refreshed_at')->nullable()->after('token_expires_at');
            $table->timestamp('consent_given_at')->nullable()->after('last_error');
            $table->foreignId('consent_given_by')->nullable()->after('consent_given_at')->constrained('users')->nullOnDelete();

            $table->index(['provider', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::table('cloud_connections', function (Blueprint $table): void {
            $table->dropIndex(['provider', 'account_id']);
            $table->dropConstrainedForeignId('consent_given_by');
            $table->dropColumn(['account_id', 'account_name', 'scopes', 'last_error', 'token_refreshed_at', 'consent_given_at']);
        });
    }
};
