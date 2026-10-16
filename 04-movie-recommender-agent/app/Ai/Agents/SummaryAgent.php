<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\RoutesThroughOpenRouter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Promptable;

/**
 * Consolida uma janela da conversa no perfil do usuário (memória longa).
 *
 * Recebe o perfil atual e as últimas mensagens, e devolve o perfil
 * atualizado: as listas e um resumo em texto (keyPreferences) que o
 * ChatAgent vai ler nas próximas mensagens e nas próximas conversas.
 *
 * Diferente do ChatAgent, não usa RemembersConversations: cada resumo é
 * uma chamada isolada, sem histórico próprio.
 */
#[Provider(Lab::OpenRouter)]
class SummaryAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable, RoutesThroughOpenRouter;

    /**
     * Sobrescreve a temperatura da trait: resumir não pede criatividade,
     * pede consistência (config/movies.php).
     */
    public function temperature(): float
    {
        return config('movies.summary_temperature');
    }

    /**
     * System prompt: papel, o que extrair e as regras de consolidação.
     */
    public function instructions(): string
    {
        return json_encode([
            'role' => 'Sumarizador de conversa sobre preferências de filmes e séries',

            'tarefa' => 'Analisar a conversa e o perfil atual, e devolver o perfil atualizado',

            'campos_para_extrair' => [
                'name' => 'Nome do usuário',
                'age' => 'Idade do usuário',
                'favoriteGenres' => 'Todos os gêneros que o usuário disse gostar',
                'favoriteTitles' => 'Filmes e séries que o usuário disse amar',
                'favoritePeople' => 'Diretores, atores e atrizes que o usuário disse gostar',
                'watched' => 'Títulos que o usuário disse já ter assistido',
                'disliked' => 'Títulos que o usuário disse não ter gostado',
                'watchingContext' => 'Com quem ou onde costuma assistir',
                'keyPreferences' => 'Sumário de 2-4 frases sobre gostos, padrões e contexto em que assiste',
                'importantContext' => 'Outros detalhes relevantes sobre o usuário',
            ],

            'regras' => [
                'Combinar informações duplicadas',
                'Ser específico sobre gêneros, títulos e pessoas',
                'Incluir associações de contexto (ex: "assiste comédia com os filhos no fim de semana")',
                'Preservar o que está no perfil_atual e não foi discutido na conversa',
                'Incluir apenas informações que o USUÁRIO declarou explicitamente',
                'Nunca incluir títulos que só a IA recomendou',
                'Ignorar o humor do momento: ele não é preferência',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Formato obrigatório da resposta. Só keyPreferences é required: o
     * resto pode faltar quando a conversa não tocou no assunto.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Nome do usuário'),
            'age' => $schema->integer()->description('Idade do usuário'),
            'favoriteGenres' => $schema->array()->items($schema->string())->description('Gêneros favoritos mencionados'),
            'favoriteTitles' => $schema->array()->items($schema->string())->description('Filmes e séries que o usuário ama'),
            'favoritePeople' => $schema->array()->items($schema->string())->description('Diretores, atores e atrizes favoritos'),
            'watched' => $schema->array()->items($schema->string())->description('Títulos que o usuário já assistiu'),
            'disliked' => $schema->array()->items($schema->string())->description('Títulos que o usuário não gostou'),
            'watchingContext' => $schema->string()->description('Com quem ou onde costuma assistir'),
            'keyPreferences' => $schema->string()
                ->description('Sumário conciso das preferências, padrões e contexto em que assiste')
                ->required(),
            'importantContext' => $schema->string()->description('Qualquer outro contexto importante sobre o usuário'),
        ];
    }

    /**
     * Mensagem enviada como "user": a conversa em texto, o perfil atual e
     * as instruções.
     *
     * As respostas do ChatAgent ficam gravadas como o JSON da saída
     * estruturada ({"message": ..., "preferences": ...}). Para o resumo só
     * interessa o que foi dito, então usamos apenas o "message".
     *
     * @param  iterable<Message>  $messages
     * @param  array<string, mixed>  $currentProfile
     */
    public static function userPrompt(iterable $messages, array $currentProfile): string
    {
        $lines = [];

        foreach ($messages as $message) {
            $lines[] = $message->role === MessageRole::User
                ? "Usuário: {$message->content}"
                : 'IA: '.(json_decode((string) $message->content, true)['message'] ?? $message->content);
        }

        return json_encode([
            'conversa' => implode("\n", $lines),
            'perfil_atual' => $currentProfile ?: 'Nenhum',
            'instrucoes' => [
                'Atualizar o perfil com as novas informações desta conversa',
                'Preservar as informações do perfil atual não discutidas nas novas mensagens',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
