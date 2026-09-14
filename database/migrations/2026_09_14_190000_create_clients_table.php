<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('document', 32)->nullable();
            $table->string('logo_path')->nullable();
            $table->json('brand_colors')->nullable();
            $table->string('timezone', 64)->default('America/Sao_Paulo');
            $table->date('contract_start')->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // Pivot que sustenta todo o multi-tenancy: um criador atende N clientes,
        // um cliente tem N aprovadores (Seção 4.2).
        Schema::create('client_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32);
            $table->boolean('is_primary_contact')->default(false);
            $table->timestamps();

            $table->unique(['client_id', 'user_id']);
            $table->index(['user_id', 'client_id']);
        });

        Schema::create('client_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('approval_required')->default(true);
            $table->boolean('internal_review_required')->default(false);
            $table->unsignedSmallInteger('approval_deadline_hours')->default(48);
            $table->boolean('auto_publish_on_approval')->default(false);
            $table->unsignedTinyInteger('min_approvals')->default(1);
            $table->json('notify_channels')->nullable();
            $table->text('brand_guidelines')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_settings');
        Schema::dropIfExists('client_user');
        Schema::dropIfExists('clients');
    }
};
