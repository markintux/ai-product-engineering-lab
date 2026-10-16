<?php

use App\Ai\Agents\ChatAgent;
use App\Models\TasteProfile;
use App\Models\User;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Prompts\AgentPrompt;

/*
|--------------------------------------------------------------------------
| sail artisan chat — testes offline
|--------------------------------------------------------------------------
|
| O comando roda o mesmo grafo da API. Aqui conferimos o que é próprio do
| terminal: a abertura da conversa, o loop de mensagens e o --resume.
|
*/

test('cumprimenta quem já tem perfil e salva o que a pessoa conta', function () {
    $bruno = User::factory()->create(['name' => 'Bruno', 'email' => 'bruno@example.com']);
    TasteProfile::factory()->for($bruno)->create(['favorite_genres' => ['Ficção científica']]);

    ChatAgent::fake([
        ['message' => 'E aí, Bruno! Que tal o filme "Duna: Parte Dois" (2024)?', 'shouldSavePreferences' => false],
        ['message' => 'Anotado, terror também!', 'preferences' => ['favoriteGenres' => ['Terror']], 'shouldSavePreferences' => true],
    ]);

    $this->artisan('chat', ['--user' => 'bruno'])
        ->expectsOutputToContain('E aí, Bruno!')
        ->expectsQuestion('Você', 'Também curto terror')
        ->expectsOutputToContain('Preferências salvas no seu perfil')
        ->expectsQuestion('Você', 'sair')
        ->assertSuccessful();

    // Abertura de quem já é conhecido: pede para citar o perfil.
    ChatAgent::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, 'mencionando o que você sabe sobre mim'));

    expect($bruno->tasteProfile->fresh()->favorite_genres)->toBe(['Ficção científica', 'Terror']);
});

test('cria o usuário que ainda não existe e começa se apresentando', function () {
    ChatAgent::fake([
        ['message' => 'Olá! Como você se chama?', 'shouldSavePreferences' => false],
    ]);

    $this->artisan('chat', ['--user' => 'carla'])
        ->expectsOutputToContain('Olá! Como você se chama?')
        ->expectsQuestion('Você', 'sair')
        ->assertSuccessful();

    $this->assertDatabaseHas('users', ['name' => 'Carla', 'email' => 'carla@example.com']);

    ChatAgent::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, 'pergunte meu nome'));
});

test('--resume continua a última conversa sem nova abertura', function () {
    $ana = User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);

    ChatAgent::fake([['message' => 'Oi, Ana!', 'shouldSavePreferences' => false]]);
    $conversationId = (new ChatAgent)->forUser($ana)->prompt('Oi')->conversationId;

    ChatAgent::fake([['message' => 'Continuando de onde paramos!', 'shouldSavePreferences' => false]]);

    $this->artisan('chat', ['--user' => 'ana', '--resume' => true])
        ->expectsOutputToContain("Continuando a conversa {$conversationId}")
        ->expectsQuestion('Você', 'E aí?')
        ->expectsOutputToContain('Continuando de onde paramos!')
        ->expectsQuestion('Você', 'sair')
        ->assertSuccessful();

    // Nenhuma conversa nova: a mensagem entrou na que já existia.
    expect(Conversation::count())->toBe(1);
    ChatAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->prompt === 'E aí?');
});
