<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| POST /api/chat — testes offline
|--------------------------------------------------------------------------
|
| Http::fake() intercepta a chamada ao OpenRouter e devolve uma resposta
| pronta. Assim os testes não gastam créditos, não dependem de rede e
| ainda conseguimos conferir exatamente o que SERIA enviado ao OpenRouter.
|
*/

beforeEach(function () {
    // Fixa o critério padrão para o teste não depender do seu .env.
    config(['model-router.sort' => 'throughput']);

    // Qualquer requisição HTTP não simulada abaixo faz o teste falhar.
    Http::preventStrayRequests();

    // Resposta no formato da API de chat do OpenRouter.
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'id' => 'gen-123',
            'model' => 'nvidia/nemotron-3.5-lightning:free',
            'choices' => [[
                'index' => 0,
                'finish_reason' => 'stop',
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Rate limiting controls how many requests a client can make.',
                ],
            ]],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 10, 'total_tokens' => 22],
        ]),
    ]);
});

test('devolve o modelo escolhido pelo OpenRouter e o texto gerado', function () {
    $this->postJson('/api/chat', ['question' => 'What is rate limiting?'])
        ->assertOk()
        ->assertExactJson([
            'model' => 'nvidia/nemotron-3.5-lightning:free',
            'sort' => 'throughput',
            'content' => 'Rate limiting controls how many requests a client can make.',
        ]);
});

test('envia os modelos candidatos e o critério pedido para o OpenRouter', function () {
    $this->postJson('/api/chat', [
        'question' => 'What is rate limiting?',
        'sort' => 'price',
    ])->assertOk();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
        && $request['models'] === config('model-router.models')
        && $request['provider'] === ['sort' => ['by' => 'price', 'partition' => 'none']]
    );
});

test('envia system prompt, temperatura e max_tokens do config', function () {
    $this->postJson('/api/chat', ['question' => 'What is rate limiting?'])->assertOk();

    Http::assertSent(fn (Request $request) => $request['messages'][0] === [
        'role' => 'system',
        'content' => config('model-router.system_prompt'),
    ]
        && $request['temperature'] === config('model-router.temperature')
        && $request['max_tokens'] === config('model-router.max_tokens')
    );
});

test('usa o critério do config quando a requisição não informa sort', function () {
    config(['model-router.sort' => 'latency']);

    $this->postJson('/api/chat', ['question' => 'What is rate limiting?'])
        ->assertOk()
        ->assertJsonPath('sort', 'latency');

    Http::assertSent(fn (Request $request) => $request['provider']['sort']['by'] === 'latency');
});

test('rejeita requisição inválida sem chamar o OpenRouter', function (array $payload, string $invalidField) {
    $this->postJson('/api/chat', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($invalidField);

    Http::assertNothingSent();
})->with([
    'sem pergunta' => [[], 'question'],
    'pergunta curta' => [['question' => 'oi'], 'question'],
    'critério desconhecido' => [['question' => 'What is rate limiting?', 'sort' => 'cheapest'], 'sort'],
]);
