<?php

/*
|--------------------------------------------------------------------------
| POST /api/chat — teste "ao vivo"
|--------------------------------------------------------------------------
|
| Chama o OpenRouter de verdade, com as datas reais de hoje, para ver os
| modelos extraindo os dados de mensagens reais. Fica fora da suíte padrão (grupo
| "live" excluído no phpunit.xml) porque depende de rede, de chave válida e
| de quais modelos gratuitos estão no ar naquele dia.
|
| Rodar: vendor/bin/sail artisan test --group=live
|
*/

beforeEach(function () {
    if (blank(config('ai.providers.openrouter.key'))) {
        $this->markTestSkipped('Defina OPENROUTER_API_KEY no .env');
    }

    $this->seed();
});

test('agenda um horário', function () {
    $response = $this->postJson('/api/chat', [
        'question' => 'Olá, sou Maria Santos e quero agendar um corte com o João Navalha para amanhã às 16h',
    ]);

    dump($response->json());

    $response->assertOk()
        ->assertJsonPath('intent', 'schedule')
        ->assertJsonPath('actionSuccess', true);
})
    ->group('live')
    ->skip(fn () => today()->addDay()->isSunday(), 'Amanhã é domingo: barbearia fechada');

test('agenda e depois cancela', function () {
    $this->postJson('/api/chat', [
        'question' => 'Sou Joao da Silva e quero agendar uma barba com o Pedro Tesoura para hoje às 15h',
    ])->assertJsonPath('actionSuccess', true);

    $response = $this->postJson('/api/chat', [
        'question' => 'Cancele meu horário com o Pedro Tesoura de hoje às 15h, me chamo Joao da Silva',
    ]);

    dump($response->json());

    $response->assertOk()
        ->assertJsonPath('intent', 'cancel')
        ->assertJsonPath('actionSuccess', true);
})
    ->group('live')
    ->skip(fn () => today()->isSunday(), 'Hoje é domingo: barbearia fechada');

test('lista horários livres', function () {
    $response = $this->postJson('/api/chat', [
        'question' => 'Quais horários o Rafa Degradê tem livres amanhã para corte e barba?',
    ]);

    dump($response->json());

    $response->assertOk()
        ->assertJsonPath('intent', 'list_slots')
        ->assertJsonPath('actionSuccess', true);

    expect($response->json('availableSlots'))->not->toBeEmpty();
})
    ->group('live')
    ->skip(fn () => today()->addDay()->isSunday(), 'Amanhã é domingo: barbearia fechada');
