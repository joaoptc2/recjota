<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloud_connections', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // Conexão pode ser da agência (client_id nulo) ou de um cliente.
            $table->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('account_email')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('root_folder_id')->nullable();
            $table->string('status', 16)->default('connected');
            $table->timestamps();

            $table->index(['client_id', 'provider']);
        });

        Schema::create('media_folders', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('media_folders')->cascadeOnDelete();
            $table->string('name');
            $table->string('path');
            $table->timestamps();

            $table->index(['client_id', 'parent_id']);
        });

        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('media_folders')->nullOnDelete();
            $table->string('source', 20)->default('upload');
            // Referência ao arquivo que permanece no Drive/OneDrive do cliente (R8).
            $table->string('external_file_id')->nullable();
            $table->foreignId('external_account_id')->nullable()->constrained('cloud_connections')->nullOnDelete();
            $table->string('filename');
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('checksum', 64)->nullable();
            // Somente derivados leves ficam no servidor.
            $table->string('local_path')->nullable();
            $table->string('local_thumb_path')->nullable();
            $table->string('local_preview_path')->nullable();
            // Ponte de mídia pública (Seção 7.4) - sempre temporária.
            $table->string('public_temp_path')->nullable();
            $table->timestamp('public_temp_expires_at')->nullable()->index();
            $table->json('tags')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'created_at']);
            $table->index(['client_id', 'checksum']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
        Schema::dropIfExists('media_folders');
        Schema::dropIfExists('cloud_connections');
    }
};
