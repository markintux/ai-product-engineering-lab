<?php

/*
|--------------------------------------------------------------------------
| Laravel AI SDK
|--------------------------------------------------------------------------
|
| Arquivo publicado pelo pacote laravel/ai e enxugado para este projeto:
| ficou apenas o provider OpenRouter. A versão completa, com todos os
| providers (OpenAI, Anthropic, Gemini...), está em
| vendor/laravel/ai/config/ai.php — copie de lá o que precisar.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Provider padrão
    |--------------------------------------------------------------------------
    |
    | Usado quando um agent não diz qual provider quer. Nosso agent já fixa
    | o OpenRouter, mas deixar o padrão alinhado evita surpresas no tinker.
    |
    */

    'default' => 'openrouter',

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | http_referer e x_title são opcionais: o OpenRouter usa esses headers
    | para identificar a sua aplicação no painel e nos rankings dele.
    |
    */

    'providers' => [
        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
            'http_referer' => env('APP_URL'),
            'x_title' => env('APP_NAME'),
        ],
    ],

];
