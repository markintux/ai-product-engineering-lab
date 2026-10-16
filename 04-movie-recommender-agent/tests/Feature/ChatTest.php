<?php

use App\Ai\Agents\ChatAgent;
use App\Ai\Agents\SummaryAgent;
use App\Models\TasteProfile;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| POST /api/chat — testes offline
|--------------------------------------------------------------------------
|
| ChatAgent::fake() e SummaryAgent::fake() fazem o SDK devolver a resposta
| que definirmos, sem chamar o OpenRouter. Assim testamos o GRAFO
| (roteamento, validação, memória) sem gastar créditos e sem depender do
| humor dos modelos gratuitos.
|
| Mesmo com fake, a memória curta é de verdade: o SDK grava cada pergunta
| e resposta nas tabelas de conversa (SQLite em memória nos testes).
|
*/

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Ana']);
});

/**
 * Finge que o ChatAgent respondeu $reply e envia $message à API como o
 * usuário do teste.
 *
 * @param  array<string, mixed>  $reply
 */
function chat(array $reply, string $message = 'Mensagem qualquer', ?string $conversationId = null): TestResponse
{
    ChatAgent::fake([$reply]);
    Sanctum::actingAs(test()->user);

    return test()->postJson('/api/chat', array_filter([
        'message' => $message,
        'conversation_id' => $conversationId,
    ]));
}

/**
 * Resposta do ChatAgent sem nada para salvar.
 *
 * @return array<string, mixed>
 */
function plainReply(string $message = 'Resposta do ChatAgent (fake).'): array
{
    return ['message' => $message, 'shouldSavePreferences' => false];
}

test('primeira mensagem abre uma conversa e devolve o estado do grafo', function () {
    $response = chat(plainReply('Oi! Como você se chama?'), 'Olá!')
        ->assertOk()
        ->assertJsonPath('message', 'Olá!')
        ->assertJsonPath('reply', 'Oi! Como você se chama?')
        ->assertJsonPath('extractedPreferences', null)
        ->assertJsonPath('needsSummarization', false)
        ->assertJsonPath('profile', null)
        // Nós que não rodaram não deixam chave no estado.
        ->assertJsonMissingPath('preferencesSaved')
        ->assertJsonMissingPath('summarized');

    $conversationId = $response->json('conversationId');

    // Memória curta: o SDK gravou a conversa, ligada ao usuário...
    $conversation = Conversation::findOrFail($conversationId);
    expect($conversation->participant_id)->toBe($this->user->id);

    // ...com a mensagem do jeito que foi digitada e a resposta.
    expect(ConversationMessage::where('conversation_id', $conversationId)->pluck('content', 'role')->all())
        ->toMatchArray(['user' => 'Olá!']);
    expect(ConversationMessage::where('conversation_id', $conversationId)->count())->toBe(2);
});

test('continua a mesma conversa quando recebe o conversation_id', function () {
    $conversationId = chat(plainReply(), 'Oi, sou a Ana')->json('conversationId');

    chat(plainReply(), 'Me indica um filme?', $conversationId)
        ->assertOk()
        ->assertJsonPath('conversationId', $conversationId);

    expect(ConversationMessage::where('conversation_id', $conversationId)->count())->toBe(4);
    expect(Conversation::count())->toBe(1);
});

test('salva as preferências extraídas e une com o perfil sem duplicar', function () {
    TasteProfile::factory()->for($this->user)->create([
        'name' => null,
        'favorite_genres' => ['Terror'],
        'favorite_titles' => [],
        'favorite_people' => [],
        'watched' => [],
        'disliked' => [],
    ]);

    chat([
        'message' => 'Anotado, Ana!',
        'preferences' => [
            'name' => 'Ana',
            'favoriteGenres' => ['terror', 'Comédia'],
            'watched' => ['Breaking Bad'],
        ],
        'shouldSavePreferences' => true,
    ], 'Sou a Ana, curto terror e comédia e já vi Breaking Bad')
        ->assertOk()
        ->assertJsonPath('preferencesSaved', true)
        ->assertJsonPath('profile.name', 'Ana')
        // "terror" já existia como "Terror": entra uma vez só.
        ->assertJsonPath('profile.favoriteGenres', ['Terror', 'Comédia'])
        ->assertJsonPath('profile.watched', ['Breaking Bad']);
});

test('não grava nada quando o modelo diz que não há preferência nova', function () {
    chat([
        'message' => 'Que bom que gostou!',
        'preferences' => ['favoriteTitles' => ['Hereditário']],
        'shouldSavePreferences' => false,
    ], 'Gostei dessa recomendação!')
        ->assertOk()
        ->assertJsonPath('extractedPreferences', null)
        ->assertJsonMissingPath('preferencesSaved');

    $this->assertDatabaseCount('taste_profiles', 0);
});

test('descarta preferências em formato inválido', function (array $preferences, string $error) {
    chat(['message' => 'Anotado!', 'preferences' => $preferences, 'shouldSavePreferences' => true])
        ->assertOk()
        ->assertJsonPath('preferencesSaved', false)
        ->assertJsonPath('preferencesError', $error);

    $this->assertDatabaseCount('taste_profiles', 0);
})->with([
    'idade por extenso' => [['age' => 'trinta'], 'The age field must be an integer.'],
    'idade impossível' => [['age' => 300], 'The age field must be between 1 and 120.'],
    'lista que veio como texto' => [['favoriteGenres' => 'terror'], 'The favorite genres field must be a list.'],
]);

test('o perfil salvo chega ao system prompt do ChatAgent', function () {
    TasteProfile::factory()->for($this->user)->create(['favorite_people' => ['Agnès Varda']]);

    chat(plainReply())->assertOk();

    ChatAgent::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->agent->instructions(), 'Agnès Varda'));
});

test('consolida o perfil quando a conversa completa uma janela', function () {
    // Janela de 4 mensagens: o resumo roda no fim da 2ª troca.
    config(['movies.window' => 4]);

    SummaryAgent::fake([[
        'favoriteGenres' => ['Terror psicológico'],
        'keyPreferences' => 'Ana ama terror, principalmente psicológico.',
    ]]);

    $conversationId = chat(plainReply('Oi, Ana!'), 'Oi!')
        ->assertJsonPath('needsSummarization', false)
        ->json('conversationId');

    SummaryAgent::assertNeverPrompted();

    chat([
        'message' => 'Terror é demais!',
        'preferences' => ['favoriteGenres' => ['Terror']],
        'shouldSavePreferences' => true,
    ], 'Amo terror', $conversationId)
        ->assertOk()
        ->assertJsonPath('needsSummarization', true)
        ->assertJsonPath('summarized', true)
        ->assertJsonPath('profile.keyPreferences', 'Ana ama terror, principalmente psicológico.')
        // O resumo soma ao que já estava salvo, não substitui a lista.
        ->assertJsonPath('profile.favoriteGenres', ['Terror', 'Terror psicológico']);

    // O SummaryAgent recebe a janela em texto (só o "message" das
    // respostas, sem o JSON da saída estruturada) e o perfil atual.
    SummaryAgent::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, 'Usuário: Amo terror')
        && str_contains($prompt->prompt, 'IA: Terror é demais!')
        && str_contains($prompt->prompt, '"perfil_atual":{"favoriteGenres":["Terror"]}'));
});

test('falha no resumo não afeta a resposta ao usuário', function () {
    config(['movies.window' => 2]);
    SummaryAgent::fake(fn () => throw new RuntimeException('Tempo esgotado'));

    chat(plainReply('Oi, Ana!'))
        ->assertOk()
        ->assertJsonPath('reply', 'Oi, Ana!')
        ->assertJsonPath('summarized', false)
        ->assertJsonPath('summaryError', 'Tempo esgotado');
});

test('falha do LLM no chat vira pedido de desculpas e não grava nada', function () {
    ChatAgent::fake(fn () => throw new RuntimeException('OpenRouter fora do ar'));
    Sanctum::actingAs($this->user);

    $this->postJson('/api/chat', ['message' => 'Me indica uma série'])
        ->assertOk()
        ->assertJsonPath('reply', 'Desculpe, encontrei um erro. Pode tentar novamente?')
        ->assertJsonPath('error', 'OpenRouter fora do ar')
        ->assertJsonPath('conversationId', null)
        ->assertJsonMissingPath('preferencesSaved');

    $this->assertDatabaseCount('taste_profiles', 0);
});

test('não deixa continuar a conversa de outro usuário', function () {
    $bruno = User::factory()->create(['name' => 'Bruno']);
    $brunosConversation = app(ConversationStore::class)
        ->storeConversation(Conversation::participantType($bruno), $bruno->id, 'Conversa do Bruno');

    ChatAgent::fake();
    Sanctum::actingAs($this->user);

    $this->postJson('/api/chat', ['message' => 'Oi', 'conversation_id' => $brunosConversation])
        ->assertNotFound();

    ChatAgent::assertNeverPrompted();
});

test('exige token: sem login responde 401 sem chamar o LLM', function () {
    ChatAgent::fake();

    $this->postJson('/api/chat', ['message' => 'Oi'])->assertUnauthorized();

    ChatAgent::assertNeverPrompted();
});

test('rejeita mensagem vazia sem chamar o LLM', function () {
    ChatAgent::fake();
    Sanctum::actingAs($this->user);

    $this->postJson('/api/chat', ['message' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('message');

    ChatAgent::assertNeverPrompted();
});

test('envia ao OpenRouter o histórico como mensagens, o JSON schema e o roteamento', function () {
    // Aqui o ChatAgent NÃO é fake: ele monta a requisição de verdade e o
    // Http::fake() intercepta, para conferirmos o que SERIA enviado.
    Http::preventStrayRequests();
    Http::fakeSequence('openrouter.ai/*')
        ->push(openRouterResponse(['message' => 'Oi! Do que você gosta?', 'shouldSavePreferences' => false]))
        ->push(openRouterResponse(['message' => 'Veja "Hereditário" (2018)!', 'shouldSavePreferences' => false]));

    Sanctum::actingAs($this->user);

    $conversationId = $this->postJson('/api/chat', ['message' => 'Oi!'])->json('conversationId');
    $this->postJson('/api/chat', ['message' => 'Me indica um terror?', 'conversation_id' => $conversationId])
        ->assertOk();

    /** @var Request $second */
    [$second] = Http::recorded()->last();

    // Memória curta: a 2ª chamada leva a 1ª troca como mensagens de verdade,
    // não como texto dentro do prompt.
    expect(array_column($second['messages'], 'role'))->toBe(['system', 'user', 'assistant', 'user'])
        ->and($second['messages'][1]['content'])->toBe('Oi!')
        ->and($second['messages'][3]['content'])->toBe('Me indica um terror?');

    expect($second->url())->toBe('https://openrouter.ai/api/v1/chat/completions')
        // Roteamento do OpenRouter (config/movies.php)
        ->and($second['models'])->toBe(config('movies.models'))
        ->and($second['provider'])->toBe(config('movies.provider'))
        // Saída estruturada: JSON schema gerado do ChatAgent::schema()
        ->and($second['response_format']['type'])->toBe('json_schema')
        ->and($second['response_format']['json_schema']['schema']['required'])->toBe(['message', 'shouldSavePreferences']);
});

/**
 * Corpo de uma resposta do OpenRouter cujo conteúdo é o JSON da saída
 * estruturada.
 *
 * @param  array<string, mixed>  $structured
 */
function openRouterResponse(array $structured): array
{
    return [
        'id' => 'gen-123',
        'model' => 'nvidia/nemotron-3-super-120b-a12b:free',
        'choices' => [[
            'index' => 0,
            'finish_reason' => 'stop',
            'message' => ['role' => 'assistant', 'content' => json_encode($structured)],
        ]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
    ];
}
