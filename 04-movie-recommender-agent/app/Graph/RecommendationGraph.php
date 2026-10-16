<?php

namespace App\Graph;

use App\Ai\Agents\ChatAgent;
use App\Ai\Agents\SummaryAgent;
use App\Models\TasteProfile;
use App\Models\User;
use App\Services\TasteProfileService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Laravel\Ai\Models\ConversationMessage;
use Throwable;

/**
 * Orquestra uma mensagem do usuário: responde, guarda o que aprendeu e,
 * de tempos em tempos, consolida a memória.
 *
 *   START → chat ─┬─ extraiu preferências? ─→ savePreferences ─┐
 *                 └─ não ──────────────────────────────────────┤
 *                                                              ├─ janela cheia? ─→ summarize ─→ END
 *                                                              └─ não ────────────────────────→ END
 *
 * Como funciona:
 *
 *   - ESTADO: um array que passa por todos os nós e acumula o que cada um
 *     produz. Chaves: message, conversationId, reply, extractedPreferences,
 *     needsSummarization, preferencesSaved, preferencesError, summarized,
 *     summaryError, error e, no fim, profile.
 *
 *   - NÓ: um método que recebe o estado e devolve SÓ as chaves que mudou.
 *
 *   - MERGE: [...$state, ...$parcial] junta o que o nó devolveu ao estado.
 *
 *   - ARESTAS CONDICIONAIS: os ifs em invoke() decidem se savePreferences
 *     e summarize rodam. Chave ausente no estado = nó que não rodou.
 *
 * As duas memórias:
 *
 *   - CURTA (a conversa): o próprio SDK grava e recarrega as mensagens
 *     (RemembersConversations no ChatAgent). O grafo só carrega o
 *     conversationId de uma mensagem para a outra.
 *
 *   - LONGA (o perfil): TasteProfile, gravado pelo TasteProfileService.
 *     Os LLMs só extraem; quem valida e grava é o código.
 */
class RecommendationGraph
{
    public function __construct(private TasteProfileService $profiles) {}

    /**
     * Roda o grafo inteiro para uma mensagem do usuário.
     *
     * @param  string|null  $conversationId  Null começa uma conversa nova.
     * @return array<string, mixed> O estado final, devolvido como JSON pela API.
     */
    public function invoke(User $user, string $message, ?string $conversationId = null): array
    {
        $state = [
            'message' => $message,
            'conversationId' => $conversationId,
        ];

        // START → chat
        $state = [...$state, ...$this->chat($user, $state)];

        // chat → savePreferences, só se o LLM extraiu algo novo.
        if (filled($state['extractedPreferences'] ?? null)) {
            $state = [...$state, ...$this->savePreferences($user, $state)];
        }

        // → summarize, só quando a conversa completou uma janela.
        if ($state['needsSummarization'] ?? false) {
            $state = [...$state, ...$this->summarize($user, $state)];
        }

        // → END. O perfil vai junto para dar para ver a memória longa mudando.
        return [...$state, 'profile' => $user->tasteProfile()->first()?->forPrompt()];
    }

    /**
     * Nó "chat": o LLM recebe o perfil (memória longa) e as últimas
     * mensagens da conversa (memória curta), responde e extrai o que o
     * usuário contou sobre si.
     *
     * Se o LLM falhar, o fluxo não quebra: a resposta vira um pedido de
     * desculpas e, sem extração, nenhum outro nó roda.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function chat(User $user, array $state): array
    {
        Log::info('💬 Chatting...');

        // Lido do banco a cada mensagem: o que foi salvo na mensagem
        // anterior já vale nesta.
        $profile = $user->tasteProfile()->first();

        try {
            $response = (new ChatAgent($profile))
                // Continua a conversa (o SDK carrega as últimas mensagens)
                // ou, sem conversationId, começa uma nova para este usuário.
                ->continueOrStart($state['conversationId'], as: $user)
                ->prompt($state['message']);
        } catch (Throwable $e) {
            Log::error("❌ Error in chat node: {$e->getMessage()}");

            return [
                'reply' => 'Desculpe, encontrei um erro. Pode tentar novamente?',
                'error' => $e->getMessage(),
            ];
        }

        Log::info("✅ Reply generated: {$response['message']}");

        return [
            // Numa conversa nova, é aqui que o ID aparece pela primeira vez.
            'conversationId' => $response->conversationId,
            'reply' => $response['message'],
            // A flag é a decisão do modelo: preferências sem a flag não são
            // gravadas. E só um objeto (array) pode seguir para a validação.
            'extractedPreferences' => ($response['shouldSavePreferences'] ?? false) && is_array($response['preferences'] ?? null)
                ? $response['preferences']
                : null,
            'needsSummarization' => $this->windowIsFull($response->conversationId),
        ];
    }

    /**
     * Nó "savePreferences": valida o que o LLM extraiu e junta ao perfil.
     * Nenhum LLM aqui, só regra de negócio.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function savePreferences(User $user, array $state): array
    {
        Log::info('💾 Saving preferences...');

        // Os dados vieram de um LLM: podem chegar em formato errado (idade
        // "trinta", lista como texto). Só grava o que passar na validação,
        // e validated() descarta chaves que não estão nas regras.
        $validator = Validator::make($state['extractedPreferences'], $this->profileRules());

        if ($validator->fails()) {
            $errors = implode(', ', $validator->errors()->all());

            Log::warning("⚠️  Preferences discarded: {$errors}");

            return [
                'preferencesSaved' => false,
                'preferencesError' => $errors,
            ];
        }

        $this->profiles->merge($user, $validator->validated());

        Log::info('✅ Preferences saved');

        return ['preferencesSaved' => true];
    }

    /**
     * Nó "summarize": o LLM lê a janela de mensagens e o perfil atual, e
     * devolve o perfil consolidado.
     *
     * Se falhar, a resposta ao usuário não é afetada. O que foi extraído
     * mensagem a mensagem já está salvo; só o texto do resumo fica
     * desatualizado até a próxima janela.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function summarize(User $user, array $state): array
    {
        Log::info('🧠 Summarizing conversation...');

        // As mesmas mensagens que o ChatAgent recebe: a janela atual.
        $messages = (new ChatAgent)->continue($state['conversationId'], as: $user)->messages();
        $currentProfile = $user->tasteProfile()->first()?->forPrompt() ?? [];

        try {
            $response = (new SummaryAgent)->prompt(SummaryAgent::userPrompt($messages, $currentProfile));
        } catch (Throwable $e) {
            Log::error("❌ Error in summarize node: {$e->getMessage()}");

            return [
                'summarized' => false,
                'summaryError' => $e->getMessage(),
            ];
        }

        $validator = Validator::make($response->toArray(), [
            ...$this->profileRules(),
            'keyPreferences' => ['required', 'string', 'max:1000'],
            'importantContext' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            $errors = implode(', ', $validator->errors()->all());

            Log::warning("⚠️  Summary discarded: {$errors}");

            return [
                'summarized' => false,
                'summaryError' => $errors,
            ];
        }

        $this->profiles->storeSummary($user, $validator->validated());

        Log::info('✅ Profile consolidated');

        return ['summarized' => true];
    }

    /**
     * A conversa acabou de completar uma janela? Com window = 6, isso
     * acontece com 6, 12, 18... mensagens gravadas.
     */
    private function windowIsFull(string $conversationId): bool
    {
        $count = ConversationMessage::query()->where('conversation_id', $conversationId)->count();

        return $count > 0 && $count % config('movies.window') === 0;
    }

    /**
     * Regras para os campos do perfil, usadas na extração e no resumo.
     *
     * @return array<string, list<string>>
     */
    private function profileRules(): array
    {
        $rules = [
            'name' => ['nullable', 'string', 'max:100'],
            'age' => ['nullable', 'integer', 'between:1,120'],
            'watchingContext' => ['nullable', 'string', 'max:255'],
        ];

        foreach (array_keys(TasteProfile::LIST_FIELDS) as $field) {
            $rules[$field] = ['nullable', 'list', 'max:20'];
            $rules["{$field}.*"] = ['string', 'max:100'];
        }

        return $rules;
    }
}
