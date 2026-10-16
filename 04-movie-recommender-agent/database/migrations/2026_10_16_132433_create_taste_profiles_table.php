<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Memória longa: o que sabemos do gosto de cada usuário.
 *
 * Um perfil por usuário (user_id único), compartilhado por todas as
 * conversas dele. As conversas em si (memória curta) ficam nas tabelas
 * agent_conversations e agent_conversation_messages, do Laravel AI SDK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taste_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Dados que a pessoa contou sobre si.
            $table->string('name')->nullable();
            $table->unsignedTinyInteger('age')->nullable();

            // Listas que só crescem: cada mensagem nova é unida ao que já existe.
            $table->json('favorite_genres')->nullable();
            $table->json('favorite_titles')->nullable();
            $table->json('favorite_people')->nullable();
            $table->json('watched')->nullable();
            $table->json('disliked')->nullable();

            // Com quem ou onde costuma assistir. O valor mais recente vence.
            $table->string('watching_context')->nullable();

            // Textos reescritos pelo resumo a cada janela de mensagens.
            $table->text('key_preferences')->nullable();
            $table->text('important_context')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taste_profiles');
    }
};
