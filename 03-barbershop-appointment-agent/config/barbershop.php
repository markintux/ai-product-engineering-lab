<?php

/*
|--------------------------------------------------------------------------
| Barbershop Appointment Agent
|--------------------------------------------------------------------------
|
| Quais modelos do OpenRouter podem responder, como o OpenRouter escolhe
| entre eles, a temperatura e o expediente da barbearia (usado pelo
| AppointmentService).
|
| Leitura no código: config('barbershop.models'), etc.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Modelos candidatos
    |--------------------------------------------------------------------------
    |
    | Os dois agents (IntentAgent e ReplyAgent) pedem saída estruturada
    | (JSON schema). Por isso a lista só pode ter modelos que suportam
    | "structured_outputs":
    | https://openrouter.ai/models?max_price=0&supported_parameters=structured_outputs
    |
    | Modelos ":free" entram e saem com frequência. Se a chamada falhar com
    | "model not found", troque o ID aqui.
    |
    */

    'models' => [
        'nvidia/nemotron-3-super-120b-a12b:free',
        'dots-studio/dots-3-note-preview:free',
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
    */

    'provider' => [
        'sort' => [
            'by' => 'throughput',
            'partition' => 'none',
        ],
    ],

    'temperature' => 0.7,

    // Segundos esperando o OpenRouter. Modelos gratuitos podem ser lentos.
    'timeout' => 120,

    /*
    |--------------------------------------------------------------------------
    | Expediente
    |--------------------------------------------------------------------------
    |
    | Segunda a sábado (domingo fechado, regra fixa no AppointmentService).
    | slot_minutes é o intervalo entre os horários sugeridos no list_slots.
    |
    */

    'opens_at' => '09:00',

    'closes_at' => '18:00',

    'slot_minutes' => 30,

];
