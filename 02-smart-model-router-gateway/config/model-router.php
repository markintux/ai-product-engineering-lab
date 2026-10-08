<?php

/*
|--------------------------------------------------------------------------
| Smart Model Router
|--------------------------------------------------------------------------
|
| Tudo o que o roteador precisa saber fica aqui. Quem decide qual modelo
| responde é o próprio OpenRouter: mandamos a lista de candidatos e o
| critério de ordenação, e ele escolhe o melhor no momento da chamada.
|
| Leitura no código: config('model-router.models'), etc.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Modelos candidatos
    |--------------------------------------------------------------------------
    |
    | IDs exatos do OpenRouter (https://openrouter.ai/models). A lista de
    | modelos ":free" muda com frequência; se um sumir, a chamada falha —
    | troque o ID aqui.
    |
    | Atenção: entre modelos gratuitos todos custam $0, então ordenar por
    | "price" vira empate. Para ver o critério de preço funcionar de verdade,
    | coloque modelos pagos com preços diferentes na lista.
    |
    */

    'models' => [
        'google/gemma-4-31b-it:free',
        'nvidia/nemotron-3.5-lightning:free',
        'liquid/lfm-2.5-2.6b:free',
    ],

    /*
    |--------------------------------------------------------------------------
    | Critério de escolha
    |--------------------------------------------------------------------------
    |
    | price      → o mais barato
    | throughput → o que gera mais tokens por segundo
    | latency    → o que começa a responder mais rápido
    |
    | Pode ser sobrescrito por requisição (campo "sort" no POST /api/chat).
    |
    */

    'sort' => env('MODEL_ROUTER_SORT', 'throughput'),

    /*
    |--------------------------------------------------------------------------
    | Parâmetros de geração
    |--------------------------------------------------------------------------
    |
    | max_tokens: limite de tokens que o modelo pode GERAR, somando o
    | raciocínio interno ("pensar antes de responder") e a resposta.
    | Os modelos gratuitos atuais raciocinam sempre (não dá para desligar),
    | gastando ~100-150 tokens só nisso. Com um limite baixo (ex: 100) o
    | raciocínio consome tudo e a resposta volta vazia, com
    | finish_reason "length".
    |
    | timeout: segundos esperando o OpenRouter. Modelos gratuitos podem ser
    | lentos; o padrão do SDK (60s) estoura com respostas longas.
    |
    */

    'system_prompt' => 'You are a helpful assistant. Answer concisely.',

    'temperature' => 0.2,

    'max_tokens' => 1000,

    'timeout' => 120,

];
