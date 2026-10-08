<?php

/*
|--------------------------------------------------------------------------
| POST /api/chat — teste "ao vivo"
|--------------------------------------------------------------------------
|
| Equivalente ao teste e2e do projeto em Node: chama o OpenRouter de
| verdade. Fica fora da suíte padrão (grupo "live" excluído no phpunit.xml)
| porque depende de rede, de chave válida e de quais modelos gratuitos
| estão no ar naquele dia.
|
| Rodar: vendor/bin/sail artisan test --group=live
|
*/

test('o OpenRouter responde usando o critério pedido', function (string $sort) {
    $response = $this->postJson('/api/chat', [
        'question' => 'What is rate limiting?',
        'sort' => $sort,
    ]);

    $response->assertOk();

    expect($response->json('model'))->toBeString()->not->toBeEmpty();

    // Mostra no terminal qual modelo venceu em cada critério.
    dump("{$sort} → {$response->json('model')}");
})
    ->with(['price', 'throughput', 'latency'])
    ->group('live')
    ->skip(fn () => blank(config('ai.providers.openrouter.key')), 'Defina OPENROUTER_API_KEY no .env');
