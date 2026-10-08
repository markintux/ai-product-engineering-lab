<?php

namespace App\Ai\Agents;

use App\Enums\SortBy;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * O "roteador inteligente" do projeto.
 *
 * Um Agent do Laravel AI SDK é a classe que sabe conversar com o LLM:
 * instruções, modelo, temperatura, etc. O trait Promptable dá o método
 * prompt(), que monta a requisição, chama o provider e devolve a resposta.
 *
 * O roteamento em si acontece no OpenRouter. Este agent só monta o pedido
 * certo: "aqui estão os modelos candidatos, escolha o melhor por <critério>".
 *
 * Uso:
 *   $response = (new ModelRouterAgent(SortBy::Price))->prompt('What is rate limiting?');
 *   $response->meta->model; // modelo que o OpenRouter escolheu
 *   $response->text;        // resposta gerada
 */
#[Provider(Lab::OpenRouter)]
class ModelRouterAgent implements Agent, HasProviderOptions
{
    use Promptable;

    /**
     * @param  SortBy|null  $sortBy  Critério desta chamada. Null usa o padrão do config/model-router.php.
     */
    public function __construct(private ?SortBy $sortBy = null) {}

    /**
     * Critério efetivo: o que veio no construtor ou o padrão do config.
     */
    public function sortBy(): SortBy
    {
        return $this->sortBy ?? SortBy::from(config('model-router.sort'));
    }

    /**
     * System prompt enviado antes da pergunta do usuário.
     */
    public function instructions(): string
    {
        return config('model-router.system_prompt');
    }

    /**
     * O SDK sempre envia um campo "model" na requisição. Usamos o primeiro
     * da lista para que o conjunto de candidatos seja exatamente a lista
     * configurada — quem decide entre eles é o "models" + "provider.sort"
     * enviados em providerOptions().
     */
    public function model(): string
    {
        return config('model-router.models')[0];
    }

    /**
     * Lido pelo SDK e enviado como "temperature".
     */
    public function temperature(): float
    {
        return config('model-router.temperature');
    }

    /**
     * Lido pelo SDK e enviado como "max_tokens".
     */
    public function maxTokens(): int
    {
        return config('model-router.max_tokens');
    }

    /**
     * Lido pelo SDK: quantos segundos esperar pela resposta do OpenRouter.
     */
    public function timeout(): int
    {
        return config('model-router.timeout');
    }

    /**
     * Campos extras, específicos do OpenRouter, que o SDK mescla no corpo
     * da requisição (interface HasProviderOptions).
     *
     * - models:            lista de candidatos que o OpenRouter pode usar.
     * - provider.sort.by:  critério de escolha (price, throughput, latency).
     * - partition "none":  compara todos os modelos juntos. Sem isso o
     *                      OpenRouter ordena só os providers de cada modelo,
     *                      respeitando a ordem da lista.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return [
            'models' => config('model-router.models'),
            'provider' => [
                'sort' => [
                    'by' => $this->sortBy()->value,
                    'partition' => 'none',
                ],
            ],
        ];
    }
}
