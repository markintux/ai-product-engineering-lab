<?php

/*
|--------------------------------------------------------------------------
| Movie Recommender Agent
|--------------------------------------------------------------------------
|
| Quais modelos do OpenRouter podem responder, como o OpenRouter escolhe
| entre eles, as temperaturas e o tamanho da janela de memória.
|
| Leitura no código: config('movies.models'), etc.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Modelos candidatos
    |--------------------------------------------------------------------------
    |
    | Os dois agents (ChatAgent e SummaryAgent) pedem saída estruturada
    | (JSON schema). Por isso a lista só pode ter modelos que suportam
    | "structured_outputs":
    | https://openrouter.ai/models?max_price=0&supported_parameters=structured_outputs
    |
    | Suportar structured_outputs não garante extrair bem. Nos testes live
    | (16/10/2026), dots-3-note-preview e nemotron-3.5-super-vl devolveram
    | o JSON no formato certo, mas com os dados nos campos errados (idade e
    | gênero dentro de watchingContext, JSON dentro de message). Por isso a
    | lista tem um modelo só. Ao trocar ou adicionar um modelo, rode
    | "sail artisan test --group=live" antes.
    |
    | Modelos ":free" entram e saem com frequência. Se a chamada falhar com
    | "model not found", troque o ID aqui.
    |
    */

    'models' => [
        'nvidia/nemotron-3-super-120b-a12b:free',
    ],

    /*
    |--------------------------------------------------------------------------
    | Roteamento do OpenRouter
    |--------------------------------------------------------------------------
    |
    | O OpenRouter escolhe, entre os modelos da lista, o de maior throughput
    | (o que gera tokens mais rápido no momento). "partition: none" faz ele
    | comparar todos os modelos juntos; sem isso a ordem da lista prevalece
    | e o critério vale só entre os providers de cada modelo.
    |
    | Com um modelo só na lista, o critério escolhe entre os providers que
    | servem esse modelo.
    |
    */

    'provider' => [
        'sort' => [
            'by' => 'throughput',
            'partition' => 'none',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Temperaturas
    |--------------------------------------------------------------------------
    |
    | O ChatAgent conversa e recomenda: um pouco de criatividade ajuda.
    | O SummaryAgent só consolida o que foi dito: quanto mais previsível,
    | melhor, então a temperatura dele é baixa.
    |
    */

    'temperature' => 0.7,

    'summary_temperature' => 0.2,

    // Segundos esperando o OpenRouter. Modelos gratuitos podem ser lentos.
    'timeout' => 120,

    /*
    |--------------------------------------------------------------------------
    | Janela de memória
    |--------------------------------------------------------------------------
    |
    | O mesmo número faz dois papéis:
    |
    |   1. O ChatAgent recebe só as últimas "window" mensagens da conversa
    |      (memória curta). A transcrição inteira continua no banco.
    |
    |   2. A cada "window" mensagens, o nó summarize consolida essa janela
    |      no perfil do usuário (memória longa).
    |
    | Como os dois valores são iguais, toda mensagem que sai da janela já
    | passou por um resumo: nada do que a pessoa contou se perde.
    |
    | 6 mensagens = 3 trocas (pergunta do usuário + resposta do agente).
    |
    */

    'window' => 6,

];
