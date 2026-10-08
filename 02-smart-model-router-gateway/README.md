# Smart Model Router Gateway (Laravel)

Gateway HTTP que recebe uma pergunta e deixa o **OpenRouter escolher qual modelo responde**,
a partir de uma lista de candidatos e de um critério: **preço**, **throughput** ou **latência**.

Porte para Laravel do [exemplo em Node.js do curso](https://github.com/unipds-engenharia-de-ia-aplicada/engenharia-de-software-com-ia-aplicada/tree/main/modulo02-integracao-apis-llms/01-smart-model-router-gateway).

**Stack:** Laravel 13 · [Laravel AI SDK](https://laravel.com/docs/ai-sdk) · Laravel Boost · Sail (Docker) · Pest

---

## Como funciona

```
POST /api/chat  {"question": "...", "sort": "price"}
      │
      ▼
ChatRequest ─────────── valida: question ≥ 5 caracteres, sort ∈ price|throughput|latency
      │
      ▼
ChatController ──────── cria o agent com o critério e chama ->prompt()
      │
      ▼
ModelRouterAgent ────── monta o pedido ao OpenRouter:
      │                   models   = lista de config/model-router.php
      │                   provider = { sort: { by: "price", partition: "none" } }
      ▼
OpenRouter ──────────── compara os candidatos, escolhe o melhor e responde
      │
      ▼
{"model": "<modelo escolhido>", "sort": "price", "content": "<resposta>"}
```

O "roteamento inteligente" é um recurso do próprio OpenRouter. O código só precisa
mandar **a lista de modelos** e **o critério**. O `partition: "none"` faz o OpenRouter
comparar todos os modelos juntos. Sem ele, a ordem da lista é respeitada e o critério
vale só entre os providers de cada modelo.

## Onde está cada coisa

| Arquivo | Papel |
|---|---|
| `config/model-router.php` | **Comece por aqui.** Modelos candidatos, critério padrão, system prompt, temperatura, max tokens |
| `config/ai.php` | Configuração do Laravel AI SDK (chave do OpenRouter) |
| `app/Ai/Agents/ModelRouterAgent.php` | O agent: lê o config e monta a requisição ao OpenRouter |
| `app/Enums/SortBy.php` | Critérios válidos: `price`, `throughput`, `latency` |
| `app/Http/Requests/ChatRequest.php` | Validação da entrada |
| `app/Http/Controllers/ChatController.php` | Recebe a pergunta, chama o agent, devolve JSON |
| `routes/api.php` | `POST /api/chat` |
| `tests/Feature/ChatTest.php` | Testes offline (OpenRouter simulado com `Http::fake`) |
| `tests/Feature/LiveRoutingTest.php` | Teste chamando o OpenRouter de verdade |

## Setup

Pré-requisito: Docker rodando.

```bash
# 1. Dependências PHP (o vendor/ não vai pro git, então o Sail ainda não existe)
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html \
    laravelsail/php84-composer:latest composer install --ignore-platform-reqs

# 2. Variáveis de ambiente: coloque sua OPENROUTER_API_KEY
cp .env.example .env

# 3. Sobe o container e prepara a aplicação
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate   # cria o SQLite (responda "yes")
```

> Dica: crie o alias `alias sail='./vendor/bin/sail'`.
> Se a porta 80 estiver ocupada (Herd, Valet, nginx...), defina `APP_PORT=8080` no `.env`.

## Usando

```bash
# critério padrão (MODEL_ROUTER_SORT do .env)
curl -X POST http://localhost/api/chat \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"question": "What is rate limiting?"}'

# escolhendo o critério na requisição
curl -X POST http://localhost/api/chat \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"question": "What is rate limiting?", "sort": "latency"}'
```

Também dá pra chamar direto no tinker:

```bash
./vendor/bin/sail artisan tinker
>>> (new App\Ai\Agents\ModelRouterAgent(App\Enums\SortBy::Price))->prompt('What is rate limiting?')->meta->model
```

## Configurando

- **Trocar os modelos:** edite `models` em `config/model-router.php`. Use IDs exatos de
  [openrouter.ai/models](https://openrouter.ai/models). Modelos `:free` entram e saem com
  frequência: se a chamada falhar com "model not found", troque o ID.
- **Critério padrão:** `MODEL_ROUTER_SORT` no `.env`.
- **Critério `price` com modelos gratuitos:** todos custam $0, então dá empate. Para ver o
  preço decidir de verdade, inclua modelos pagos com preços diferentes.
- **Resposta vazia (`"content": ""`)?** Os modelos de raciocínio "pensam" antes de responder,
  e esses tokens contam no `max_tokens`. Se o limite for baixo, o raciocínio gasta tudo e não
  sobra nada para a resposta. Por isso o padrão é `1000`, e não os `100` da versão Node.

## Testes

```bash
./vendor/bin/sail artisan test                # suíte padrão: offline, não gasta créditos
./vendor/bin/sail artisan test --group=live   # chama o OpenRouter de verdade (precisa da chave)
```

Os testes offline verificam o que **seria enviado** ao OpenRouter (modelos, critério,
temperatura...) e como a resposta é devolvida. O teste `live` mostra qual modelo venceu
em cada critério.

## Diferenças em relação à versão Node

| Node (curso) | Laravel |
|---|---|
| `@openrouter/sdk` | Laravel AI SDK (`laravel/ai`), provider `openrouter` |
| `config.ts` | `config/model-router.php` + `.env` |
| `OpenRouterService` | `ModelRouterAgent` (campos extras via `HasProviderOptions`) |
| Fastify + JSON schema | Rota + `ChatRequest` (FormRequest) + controller de ação única |
| Critério fixo no config | Critério no config **ou** por requisição (`sort`) |
| Testes e2e contra a API real | Testes offline + grupo `live` opcional |
