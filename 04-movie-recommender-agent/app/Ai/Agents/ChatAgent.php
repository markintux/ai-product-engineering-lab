<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\RoutesThroughOpenRouter;
use App\Models\TasteProfile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Conversa com o usuário, recomenda filmes e séries e extrai o que ele
 * conta sobre o próprio gosto.
 *
 * Duas memórias chegam até o modelo:
 *
 *   - LONGA: o perfil do usuário (TasteProfile), recebido no construtor e
 *     colocado no system prompt (instructions()).
 *
 *   - CURTA: as últimas mensagens desta conversa. Quem cuida dela é a
 *     trait RemembersConversations do SDK: antes de chamar o LLM, carrega
 *     as mensagens anteriores (messages()); depois da resposta, grava a
 *     pergunta e a resposta nas tabelas agent_conversations e
 *     agent_conversation_messages. O histórico vai ao modelo como
 *     mensagens "user" e "assistant" de verdade.
 *
 * Por isso o prompt do usuário é só o que ele digitou (é isso que fica
 * na transcrição) e todas as regras ficam no system prompt.
 *
 * Saída estruturada (HasStructuredOutput): o SDK envia ao OpenRouter um
 * "response_format" json_schema montado a partir de schema(), e devolve
 * a resposta como array: $response['message'], $response['preferences']...
 *
 * Os prompts são montados como JSON (json_encode): chaves explícitas
 * ajudam o modelo a separar regras, dados e exemplos.
 */
#[Provider(Lab::OpenRouter)]
class ChatAgent implements Agent, Conversational, HasProviderOptions, HasStructuredOutput
{
    use Promptable, RemembersConversations, RoutesThroughOpenRouter;

    /**
     * @param  TasteProfile|null  $profile  Memória longa. Null para quem ainda não contou nada.
     */
    public function __construct(private ?TasteProfile $profile = null) {}

    /**
     * System prompt: papel, tarefas, o perfil do usuário, as regras de
     * recomendação e de extração, e exemplos.
     */
    public function instructions(): string
    {
        return json_encode([
            'role' => 'Assistente de filmes e séries entusiasta e amigável - caloroso, animado, conversacional (2-4 frases)',

            'tarefas' => [
                'Conversar sobre filmes e séries e fazer recomendações personalizadas',
                'Extrair o que o usuário contar sobre si (nome, idade, gêneros, títulos e pessoas favoritas, o que já assistiu, o que não gostou, com quem ou onde assiste)',
                'Fazer perguntas de acompanhamento para entender melhor o gosto do usuário',
                'SEMPRE recomendar títulos específicos (título, ano e se é filme ou série) baseado no que sabe do usuário',
                'Se houver perfil_do_usuario, reconheça-o e construa sobre esse conhecimento',
                'Responder sempre em português',
            ],

            'perfil_do_usuario' => $this->profile?->forPrompt() ?: 'Nenhum',

            'regras_de_recomendacao' => [
                'nao_repetir' => 'Nunca recomende títulos que estão em watched, disliked ou favoriteTitles do perfil: a pessoa já assistiu',
                'classificacao_indicativa' => 'Se a idade do perfil for menor que 18, recomende apenas títulos com classificação indicativa compatível',
                'streaming' => 'Nunca afirme em qual serviço de streaming um título está: você não conhece o catálogo atual',
            ],

            'campos_para_extrair' => [
                'name' => 'Nome do usuário',
                'age' => 'Idade do usuário, como número (ex: "tenho 30 anos" → 30)',
                'favoriteGenres' => 'Gêneros que o usuário disse gostar (ex: terror, comédia)',
                'favoriteTitles' => 'Filmes e séries que o usuário disse amar',
                'favoritePeople' => 'Diretores, atores e atrizes que o usuário disse gostar',
                'watched' => 'Títulos que o usuário disse já ter assistido',
                'disliked' => 'Títulos que o usuário disse não ter gostado',
                'watchingContext' => 'SOMENTE com quem ou onde costuma assistir (ex: "com o marido, aos sábados"). Nunca use para idade, gêneros ou títulos',
            ],

            'regras_de_extracao' => [
                'shouldSavePreferences' => 'Defina como true APENAS quando a ÚLTIMA mensagem do usuário trouxer NOVAS informações sobre ele',
                'extrair_somente' => 'O que o USUÁRIO declarou explicitamente sobre si na última mensagem',
                'nunca_extrair' => 'Títulos que VOCÊ (IA) recomendou - apenas o que o USUÁRIO disse',
                'nao_extrair' => 'Saudações simples, perguntas sem informação nova, reações genéricas',
                'humor' => 'Humor do momento ("hoje estou triste") NÃO é preferência: use para escolher a recomendação, mas não extraia',
                'assistidos' => 'Já assistiu: watched. Assistiu e não gostou: disliked. Disse que ama: favoriteTitles',
            ],

            'exemplos' => [
                [
                    'usuario' => 'Oi! Meu nome é Ana e eu amo filmes de terror',
                    'resposta' => [
                        'message' => 'Oi, Ana! Terror é demais! Você curte mais sobrenatural ou suspense psicológico? Pra começar, recomendo o filme "Hereditário" (2018)!',
                        'preferences' => ['name' => 'Ana', 'favoriteGenres' => ['terror']],
                        'shouldSavePreferences' => true,
                    ],
                ],
                [
                    'usuario' => 'Sou o Pedro, tenho 25 anos e curto comédia',
                    'resposta' => [
                        'message' => 'Prazer, Pedro! Comédia é ótima pra relaxar. Já viu a série "Brooklyn Nine-Nine" (2013)? É hilária!',
                        'preferences' => ['name' => 'Pedro', 'age' => 25, 'favoriteGenres' => ['comédia']],
                        'shouldSavePreferences' => true,
                    ],
                ],
                [
                    'usuario' => 'Pode me recomendar uma série?',
                    'resposta' => [
                        'message' => 'Claro! Pelo seu gosto por terror, experimente a série "A Maldição da Residência Hill" (2018). Quer algo mais leve também?',
                        'shouldSavePreferences' => false,
                    ],
                ],
                [
                    'usuario' => 'Gostei dessas recomendações!',
                    'contexto' => 'IA acabou de recomendar "Hereditário" e "A Maldição da Residência Hill"',
                    'resposta' => [
                        'message' => 'Que bom que gostou! Quer mais terror ou prefere explorar outro gênero?',
                        'shouldSavePreferences' => false,
                        'nota_importante' => 'NÃO extraia "Hereditário" nem "A Maldição da Residência Hill": foram SUAS recomendações, não escolhas do usuário',
                    ],
                ],
                [
                    'usuario' => 'Já assisti Breaking Bad e não curti Lost',
                    'resposta' => [
                        'message' => 'Anotado! Se Breaking Bad te prendeu, a série "Ozark" (2017) tem a mesma tensão. E prometo não te indicar nada parecido com Lost!',
                        'preferences' => ['watched' => ['Breaking Bad'], 'disliked' => ['Lost']],
                        'shouldSavePreferences' => true,
                    ],
                ],
                [
                    'usuario' => 'Adoro os filmes do Christopher Nolan, costumo assistir com meu marido no sábado',
                    'resposta' => [
                        'message' => 'Ótima escolha para o sábado! Se ainda não viram, o filme "O Grande Truque" (2006) é Nolan no auge, cheio de reviravoltas!',
                        'preferences' => ['favoritePeople' => ['Christopher Nolan'], 'watchingContext' => 'Com o marido, aos sábados'],
                        'shouldSavePreferences' => true,
                        'nota_importante' => 'EXTRAIR: o usuário declarou explicitamente que GOSTA do diretor e contou com quem assiste',
                    ],
                ],
                [
                    'usuario' => 'Hoje estou meio pra baixo',
                    'resposta' => [
                        'message' => 'Poxa, sinto muito! Que tal algo leve e acolhedor, como o filme "Paddington 2" (2017)?',
                        'shouldSavePreferences' => false,
                        'nota_importante' => 'Humor do momento: use na recomendação, mas NÃO extraia',
                    ],
                ],
                [
                    'usuario' => 'Olá!',
                    'resposta' => [
                        'message' => 'Olá! Sou seu assistente de filmes e séries! Como você se chama e o que gosta de assistir? 🎬',
                        'shouldSavePreferences' => false,
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Tamanho da janela de memória curta: quantas mensagens anteriores da
     * conversa o SDK carrega e envia ao modelo (config/movies.php).
     */
    protected function maxConversationMessages(): int
    {
        return config('movies.window');
    }

    /**
     * Formato obrigatório da resposta. "preferences" é opcional: só vem
     * quando a última mensagem trouxe algo sobre o usuário. Quem confere
     * os tipos e tamanhos antes de gravar é o nó savePreferences do grafo.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'message' => $schema->string()
                ->description('A resposta conversacional para o usuário')
                ->required(),
            'preferences' => $schema->object([
                'name' => $schema->string()->description('Nome do usuário'),
                'age' => $schema->integer()->description('Idade do usuário'),
                'favoriteGenres' => $schema->array()->items($schema->string())->description('Gêneros favoritos'),
                'favoriteTitles' => $schema->array()->items($schema->string())->description('Filmes e séries que o usuário ama'),
                'favoritePeople' => $schema->array()->items($schema->string())->description('Diretores, atores e atrizes favoritos'),
                'watched' => $schema->array()->items($schema->string())->description('Títulos que o usuário já assistiu'),
                'disliked' => $schema->array()->items($schema->string())->description('Títulos que o usuário não gostou'),
                'watchingContext' => $schema->string()->description('Com quem ou onde costuma assistir'),
            ])->description('Preferências que o usuário declarou na última mensagem'),
            'shouldSavePreferences' => $schema->boolean()
                ->description('Se as preferências extraídas devem ser salvas')
                ->required(),
        ];
    }
}
