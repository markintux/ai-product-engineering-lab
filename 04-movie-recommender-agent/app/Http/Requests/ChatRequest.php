<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Laravel\Ai\Models\Conversation;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Valida o corpo do POST /api/chat antes de chegar no controller.
 *
 * Se algo falhar aqui, o grafo nem é executado e nenhuma chamada ao LLM
 * é feita.
 */
class ChatRequest extends FormRequest
{
    /**
     * Quem chegou aqui já tem token válido (a rota usa auth:sanctum). Falta
     * conferir o dono da conversa: o SDK continua qualquer conversa pelo
     * ID, sem checar de quem ela é. Sem esta verificação, um usuário leria
     * e escreveria na conversa de outro só trocando o conversation_id.
     */
    public function authorize(): bool
    {
        $conversationId = $this->input('conversation_id');

        return $conversationId === null || Conversation::query()
            ->whereKey($conversationId)
            ->whereMorphedTo('participant', $this->user())
            ->exists();
    }

    /**
     * 404 e não 403: para quem não é dono, a conversa "não existe". Um 403
     * confirmaria que aquele ID pertence a outra pessoa.
     */
    protected function failedAuthorization(): void
    {
        throw new NotFoundHttpException('Conversa não encontrada.');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Limite de tamanho: a mensagem vai inteira para o LLM e fica
            // gravada na conversa.
            'message' => ['required', 'string', 'max:2000'],
            // Sem conversation_id, o grafo começa uma conversa nova.
            'conversation_id' => ['nullable', 'uuid'],
        ];
    }
}
