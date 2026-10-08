<?php

namespace App\Http\Requests;

use App\Enums\SortBy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Valida o corpo do POST /api/chat antes de chegar no controller.
 *
 * Se a validação falhar, o Laravel responde 422 com os erros em JSON
 * sozinho — o controller nem é executado.
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
            // Mesma regra do projeto em Node: texto com pelo menos 5 caracteres.
            'question' => ['required', 'string', 'min:5'],

            // Opcional: permite testar outro critério sem mexer no .env.
            'sort' => ['nullable', Rule::enum(SortBy::class)],
        ];
    }
}
