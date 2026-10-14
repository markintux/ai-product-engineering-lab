<?php

namespace App\Http\Controllers;

use App\Graph\AppointmentGraph;
use App\Http\Requests\ChatRequest;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/chat
 *
 * Recebe a pergunta já validada, roda o grafo e devolve o estado final em
 * JSON (mensagens, intent, dados extraídos e resultado da ação).
 *
 * O Laravel cria o AppointmentGraph (e o AppointmentService que ele
 * recebe no construtor) sozinho, pelo container de injeção de dependências.
 *
 * Se algo inesperado estourar, o Laravel responde 500 em JSON
 * (bootstrap/app.php → shouldRenderJsonWhen para rotas api/*).
 */
class ChatController extends Controller
{
    public function __invoke(ChatRequest $request, AppointmentGraph $graph): JsonResponse
    {
        $state = $graph->invoke($request->validated('question'));

        // JSON_UNESCAPED_UNICODE: mostra "João" em vez de "João" no curl.
        return response()->json($state, options: JSON_UNESCAPED_UNICODE);
    }
}
