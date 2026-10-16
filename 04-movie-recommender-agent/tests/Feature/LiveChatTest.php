<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| POST /api/chat — testes "ao vivo"
|--------------------------------------------------------------------------
|
| Chamam o OpenRouter de verdade, para ver os modelos extraindo e lembrando
| preferências em mensagens reais. Ficam fora da suíte padrão (grupo
| "live" excluído no phpunit.xml) porque dependem de rede, de chave válida
| e de quais modelos gratuitos estão no ar naquele dia.
|
| Rodar: vendor/bin/sail artisan test --group=live
|
*/

beforeEach(function () {
    if (blank(config('ai.providers.openrouter.key'))) {
        $this->markTestSkipped('Defina OPENROUTER_API_KEY no .env');
    }

    Sanctum::actingAs(User::factory()->create());
});

test('lembra o que a pessoa contou, inclusive numa conversa nova', function () {
    $first = $this->postJson('/api/chat', [
        'message' => 'Oi! Me chamo Júlia, tenho 30 anos e sou fã de comédia romântica',
    ]);

    dump($first->json());

    $first->assertOk()->assertJsonPath('preferencesSaved', true);
    expect($first->json('profile.name'))->toBe('Júlia')
        ->and($first->json('profile.age'))->toBe(30)
        ->and(mb_strtolower(implode(' ', $first->json('profile.favoriteGenres'))))->toContain('comédia');

    // Conversa NOVA (sem conversation_id): a memória curta está vazia, só
    // o perfil (memória longa) sabe quem é a Júlia.
    $second = $this->postJson('/api/chat', [
        'message' => 'Qual é o meu nome e de que gênero eu gosto?',
    ]);

    dump($second->json());

    $second->assertOk();
    expect($second->json('conversationId'))->not->toBe($first->json('conversationId'))
        ->and(mb_strtolower($second->json('reply')))->toContain('júlia')
        ->and(mb_strtolower($second->json('reply')))->toContain('comédia');
})->group('live');

test('lembra o que foi dito antes na mesma conversa', function () {
    $first = $this->postJson('/api/chat', [
        'message' => 'Já vi The Office inteira e achei Game of Thrones muito chato',
    ]);

    dump($first->json());

    $first->assertOk();
    expect($first->json('profile.watched'))->toContain('The Office')
        ->and($first->json('profile.disliked'))->toContain('Game of Thrones');

    // Mesma conversa: a resposta depende da mensagem anterior.
    $second = $this->postJson('/api/chat', [
        'message' => 'Qual série eu acabei de dizer que achei chata?',
        'conversation_id' => $first->json('conversationId'),
    ]);

    dump($second->json());

    $second->assertOk()->assertJsonPath('conversationId', $first->json('conversationId'));
    expect(mb_strtolower($second->json('reply')))->toContain('game of thrones');
})->group('live');

test('consolida o perfil com o resumo da conversa', function () {
    // Janela de 2 mensagens: o resumo roda já na primeira troca.
    config(['movies.window' => 2]);

    $response = $this->postJson('/api/chat', [
        'message' => 'Sou o Caio, adoro animações do Studio Ghibli e assisto com meus filhos no domingo',
    ]);

    dump($response->json());

    $response->assertOk()->assertJsonPath('summarized', true);
    expect($response->json('profile.keyPreferences'))->not->toBeEmpty()
        ->and(mb_strtolower($response->json('profile.keyPreferences')))->toContain('ghibli');
})->group('live');
