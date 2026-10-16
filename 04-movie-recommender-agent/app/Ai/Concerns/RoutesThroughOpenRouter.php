<?php

namespace App\Ai\Concerns;

use Laravel\Ai\Enums\Lab;

/**
 * Configuração do OpenRouter compartilhada pelos dois agents.
 *
 * O Laravel AI SDK lê estes métodos direto do agent na hora de montar a
 * requisição: model() vira "model", temperature() vira "temperature",
 * timeout() é quanto esperar pela resposta, e providerOptions() é
 * mesclado no corpo da requisição.
 *
 * A classe que usa esta trait precisa implementar HasProviderOptions,
 * senão o SDK ignora o providerOptions().
 */
trait RoutesThroughOpenRouter
{
    /**
     * O SDK sempre envia um campo "model". Usamos o primeiro da lista;
     * quem decide de fato entre os candidatos é o OpenRouter, com o
     * "models" + "provider" enviados em providerOptions().
     */
    public function model(): string
    {
        return config('movies.models')[0];
    }

    public function temperature(): float
    {
        return config('movies.temperature');
    }

    public function timeout(): int
    {
        return config('movies.timeout');
    }

    /**
     * Campos específicos do OpenRouter, mesclados no corpo da requisição:
     *
     * - models:   modelos candidatos que o OpenRouter pode usar.
     * - provider: critério para escolher entre eles (config/movies.php).
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return [
            'models' => config('movies.models'),
            'provider' => config('movies.provider'),
        ];
    }
}
