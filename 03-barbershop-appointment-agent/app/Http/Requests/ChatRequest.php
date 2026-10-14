<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida o corpo do POST /api/chat antes de chegar no controller.
 *
 * Se a validação falhar, o Laravel responde 422 com os erros em JSON
 * sozinho: o grafo nem é executado e nenhuma chamada ao LLM é feita.
 */
class ChatRequest extends FormRequest
{
    /**
     * Rota pública: qualquer um pode chamar.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Pelo menos 10 caracteres: mensagens curtas demais não têm
            // informação para extrair e só gastariam uma chamada ao LLM.
            'question' => ['required', 'string', 'min:10'],
        ];
    }
}
