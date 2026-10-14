<?php

use App\Ai\Agents\IntentAgent;
use App\Ai\Agents\ReplyAgent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| POST /api/chat — testes offline
|--------------------------------------------------------------------------
|
| IntentAgent::fake() e ReplyAgent::fake() fazem o SDK devolver a resposta
| que definirmos, sem chamar o OpenRouter. Assim testamos o GRAFO
| (roteamento, validação, regras da agenda) sem gastar créditos e sem
| depender do humor dos modelos gratuitos.
|
| Relógio fixo: terça, 13/10/2026 08:00. O seeder cria:
|   - João Navalha  hoje   11:00–11:30  corte  Joao da Silva
|   - Pedro Tesoura amanhã 14:00–14:20  barba  Luana Costa
|
*/

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-13 08:00'));
    $this->seed();

    ReplyAgent::fake([['message' => 'Resposta gerada pelo ReplyAgent (fake).']]);
});

/**
 * Finge que o IntentAgent "entendeu" $intent da mensagem e chama a API.
 *
 * @param  array<string, mixed>  $intent
 */
function chat(array $intent, string $question = 'Mensagem qualquer do cliente'): TestResponse
{
    IntentAgent::fake([$intent]);

    return test()->postJson('/api/chat', ['question' => $question]);
}

test('agenda um horário livre e devolve o estado do grafo em JSON', function () {
    chat([
        'intent' => 'schedule',
        'barberId' => 1,
        'barberName' => 'João',
        'service' => 'corte',
        'datetime' => '2026-10-14 16:00',
        'customerName' => 'Maria Santos',
    ], 'Sou Maria Santos, quero um corte com o João amanhã às 16h')
        ->assertOk()
        ->assertJsonPath('intent', 'schedule')
        ->assertJsonPath('actionSuccess', true)
        ->assertJsonPath('appointmentData.starts_at', '2026-10-14 16:00')
        ->assertJsonPath('appointmentData.ends_at', '2026-10-14 16:30')
        ->assertJsonPath('messages', [
            ['role' => 'user', 'content' => 'Sou Maria Santos, quero um corte com o João amanhã às 16h'],
            ['role' => 'assistant', 'content' => 'Resposta gerada pelo ReplyAgent (fake).'],
        ]);

    $this->assertDatabaseHas('appointments', [
        'barber_id' => 1,
        'customer_name' => 'Maria Santos',
        'starts_at' => '2026-10-14 16:00:00',
    ]);
});

test('recusa horário que se sobrepõe a outro agendamento', function () {
    // Corte + barba 10:30–11:20 invade o corte do Joao da Silva (11:00–11:30).
    chat([
        'intent' => 'schedule',
        'barberId' => 1,
        'service' => 'corte_barba',
        'datetime' => '2026-10-13 10:30',
        'customerName' => 'Maria Santos',
    ])
        ->assertOk()
        ->assertJsonPath('actionSuccess', false)
        ->assertJsonPath('actionError', 'Horário indisponível para este barbeiro');

    $this->assertDatabaseCount('appointments', 2);

    // O motivo do erro chega ao ReplyAgent para ele explicar ao cliente.
    ReplyAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, '"scenario":"schedule_error"')
        && str_contains($prompt->prompt, 'Horário indisponível para este barbeiro'));
});

test('recusa horário fora do expediente', function (string $datetime, string $error) {
    chat([
        'intent' => 'schedule',
        'barberId' => 2,
        'service' => 'corte',
        'datetime' => $datetime,
        'customerName' => 'Maria Santos',
    ])
        ->assertJsonPath('actionSuccess', false)
        ->assertJsonPath('actionError', $error);
})->with([
    'antes de abrir' => ['2026-10-14 08:30', 'Fora do horário de funcionamento (das 09:00 às 18:00)'],
    'termina depois de fechar' => ['2026-10-14 17:45', 'Fora do horário de funcionamento (das 09:00 às 18:00)'],
    'domingo' => ['2026-10-18 10:00', 'A barbearia não abre aos domingos'],
]);

test('cancela um agendamento existente', function () {
    chat([
        'intent' => 'cancel',
        'barberId' => 1,
        'datetime' => '2026-10-13 11:00',
        'customerName' => 'Joao da Silva',
    ])
        ->assertJsonPath('intent', 'cancel')
        ->assertJsonPath('actionSuccess', true)
        ->assertJsonPath('appointmentData.service', 'corte');

    $this->assertDatabaseMissing('appointments', ['customer_name' => 'Joao da Silva']);

    // O ReplyAgent recebe o que foi cancelado, para não inventar o serviço.
    ReplyAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, '"scenario":"cancel_success"')
        && str_contains($prompt->prompt, '"service":"corte"'));
});

test('não cancela agendamento que não existe para aquele cliente', function () {
    chat([
        'intent' => 'cancel',
        'barberId' => 1,
        'datetime' => '2026-10-13 11:00',
        'customerName' => 'Maria Santos',
    ])
        ->assertJsonPath('actionSuccess', false)
        ->assertJsonPath('actionError', 'Agendamento não encontrado para cancelamento');

    $this->assertDatabaseCount('appointments', 2);
});

test('lista horários livres considerando a duração do serviço', function () {
    // João tem corte 11:00–11:30, e corte + barba dura 50 min.
    $response = chat([
        'intent' => 'list_slots',
        'barberId' => 1,
        'service' => 'corte_barba',
        'datetime' => '2026-10-13 00:00',
    ])->assertJsonPath('actionSuccess', true);

    expect($response->json('availableSlots'))
        ->toContain('09:00', '10:00', '11:30', '17:00')
        ->not->toContain('10:30')  // 10:30–11:20 invade o corte das 11:00
        ->not->toContain('11:00')  // ocupado
        ->not->toContain('17:30'); // 17:30–18:20 passa do fechamento
});

test('mensagem fora do escopo não executa ação e vai direto para a resposta', function () {
    chat(['intent' => 'unknown'], 'Qual a previsão do tempo hoje?')
        ->assertOk()
        ->assertJsonPath('intent', 'unknown')
        ->assertJsonMissingPath('actionSuccess')
        ->assertJsonPath('messages.1.role', 'assistant');

    ReplyAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, '"scenario":"unknown"'));
});

test('valida os dados extraídos pelo LLM antes de agir', function (array $intent, string $error) {
    chat($intent)
        ->assertJsonPath('actionSuccess', false)
        ->assertJsonPath('actionError', $error);

    $this->assertDatabaseCount('appointments', 2);
})->with([
    'sem nome do cliente' => [
        ['intent' => 'schedule', 'barberId' => 1, 'datetime' => '2026-10-14 16:00'],
        'The customer name field is required.',
    ],
    'barbeiro inventado pelo LLM' => [
        ['intent' => 'schedule', 'barberId' => 99, 'datetime' => '2026-10-14 16:00', 'customerName' => 'Maria Santos'],
        'The selected barber id is invalid.',
    ],
    'data fora do formato combinado' => [
        ['intent' => 'cancel', 'barberId' => 1, 'datetime' => '2026-10-13T11:00:00Z', 'customerName' => 'Joao da Silva'],
        'The datetime field must match the format Y-m-d H:i.',
    ],
]);

test('falha do LLM no identifyIntent vira "unknown" sem derrubar a API', function () {
    IntentAgent::fake(fn () => throw new RuntimeException('OpenRouter fora do ar'));

    $this->postJson('/api/chat', ['question' => 'Quero cortar o cabelo amanhã'])
        ->assertOk()
        ->assertJsonPath('intent', 'unknown')
        ->assertJsonPath('error', 'OpenRouter fora do ar')
        ->assertJsonPath('messages.1.role', 'assistant');
});

test('rejeita pergunta curta sem chamar o LLM', function () {
    IntentAgent::fake();

    $this->postJson('/api/chat', ['question' => 'oi'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('question');

    IntentAgent::assertNeverPrompted();
});

test('envia ao OpenRouter o JSON schema, os modelos e o critério de roteamento', function () {
    // Aqui o IntentAgent NÃO é fake: ele monta a requisição de verdade e o
    // Http::fake() intercepta, para conferirmos o que SERIA enviado.
    Http::preventStrayRequests();
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'id' => 'gen-123',
            'model' => 'nvidia/nemotron-3-super-120b-a12b:free',
            'choices' => [[
                'index' => 0,
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => '{"intent":"unknown"}'],
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]),
    ]);

    $this->postJson('/api/chat', ['question' => 'Qual a previsão do tempo hoje?'])
        ->assertOk()
        ->assertJsonPath('intent', 'unknown');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
        // Roteamento do OpenRouter (config/barbershop.php)
        && $request['models'] === config('barbershop.models')
        && $request['provider'] === config('barbershop.provider')
        && $request['temperature'] === config('barbershop.temperature')
        // Saída estruturada: JSON schema gerado do IntentAgent::schema()
        && $request['response_format']['type'] === 'json_schema'
        && $request['response_format']['json_schema']['schema']['required'] === ['intent']
        // System prompt montado com os barbeiros do banco
        && str_contains($request['messages'][0]['content'], 'João Navalha')
    );
});
