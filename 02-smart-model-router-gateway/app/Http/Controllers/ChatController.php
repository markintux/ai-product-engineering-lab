<?php

namespace App\Http\Controllers;

use App\Ai\Agents\ModelRouterAgent;
use App\Enums\SortBy;
use App\Http\Requests\ChatRequest;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/chat
 *
 * Controller de ação única (__invoke): recebe a pergunta já validada,
 * repassa para o agent e devolve qual modelo respondeu e o texto.
 *
 * Se o OpenRouter falhar (chave inválida, modelo fora do ar...), o SDK
 * lança uma exceção e o Laravel responde 500 em JSON — configurado em
 * bootstrap/app.php (shouldRenderJsonWhen para rotas api/*).
 */
class ChatController extends Controller
{
    public function __invoke(ChatRequest $request): JsonResponse
    {
        // enum() converte o texto "price" em SortBy::Price (ou null se não veio).
        $agent = new ModelRouterAgent($request->enum('sort', SortBy::class));

        $response = $agent->prompt($request->validated('question'));

        return response()->json([
            'model' => $response->meta->model,
            'sort' => $agent->sortBy()->value,
            'content' => $response->text,
        ]);
    }
}
