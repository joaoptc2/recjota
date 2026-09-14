<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Colunas nullable de propósito: métrica que a API não devolveu é
        // "indisponível", nunca zero (Seção 6.8 / 14).
        Schema::create('metrics_account_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('followers')->nullable();
            $table->unsignedBigInteger('follows')->nullable();
            $table->unsignedBigInteger('reach')->nullable();
            $table->unsignedBigInteger('impressions')->nullable();
            $table->unsignedBigInteger('profile_views')->nullable();
            $table->unsignedBigInteger('website_clicks')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['social_account_id', 'date']);
        });

        Schema::create('metrics_post', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->timestamp('collected_at');
            $table->unsignedBigInteger('reach')->nullable();
            $table->unsignedBigInteger('impressions')->nullable();
            $table->unsignedBigInteger('likes')->nullable();
            $table->unsignedBigInteger('comments')->nullable();
            $table->unsignedBigInteger('saves')->nullable();
            $table->unsignedBigInteger('shares')->nullable();
            $table->unsignedBigInteger('video_views')->nullable();
            $table->decimal('engagement_rate', 8, 4)->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['post_id', 'collected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metrics_post');
        Schema::dropIfExists('metrics_account_daily');
    }
};
