<?php

namespace App\Http\Controllers;

use App\Graph\RecommendationGraph;
use App\Http\Requests\ChatRequest;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/chat
 *
 * Recebe a mensagem já validada, roda o grafo para o usuário do token e
 * devolve o estado final em JSON (resposta, conversa, o que foi extraído
 * e o perfil atualizado).
 *
 * Para continuar a conversa, o cliente manda de volta o conversationId
 * da resposta anterior no campo conversation_id.
 *
 * O Laravel cria o RecommendationGraph (e o TasteProfileService que ele
 * recebe no construtor) sozinho, pelo container de injeção de dependências.
 */
class ChatController extends Controller
{
    public function __invoke(ChatRequest $request, RecommendationGraph $graph): JsonResponse
    {
        $state = $graph->invoke(
            $request->user(),
            $request->validated('message'),
            $request->validated('conversation_id'),
        );

        // JSON_UNESCAPED_UNICODE: mostra "Ficção" em vez de "Ficção" no curl.
        return response()->json($state, options: JSON_UNESCAPED_UNICODE);
    }
}
