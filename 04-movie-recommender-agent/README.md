# Movie Recommender Agent (Laravel)

Assistente que conversa sobre filmes e séries, recomenda títulos e **lembra de você**: o que você
contou numa mensagem vale para as próximas e também para conversas futuras.

**Stack:** Laravel 13 · [Laravel AI SDK](https://laravel.com/docs/ai-sdk) · Sanctum · Laravel Boost · Sail (Docker) · Pest

---

## Como funciona

A ideia central: **duas memórias**.

| Memória | Escopo | Onde fica | Quem grava |
|---|---|---|---|
| **Curta** | Uma conversa | Tabelas `agent_conversations` e `agent_conversation_messages` | O Laravel AI SDK (`RemembersConversations` no `ChatAgent`) |
| **Longa** | O usuário, em todas as conversas | Tabela `taste_profiles` | O `TasteProfileService`, com o que os LLMs extraem |

Cada mensagem passa por um grafo:

```
POST /api/chat {"message": "...", "conversation_id": null}   (token Sanctum → usuário)
sail artisan chat --user=ana                                  (mesmo grafo, no terminal)
      │
      ▼
chat ── ChatAgent (LLM + saída estruturada + RemembersConversations)
      │   recebe: perfil do usuário (system prompt) + últimas 6 mensagens da conversa + mensagem atual
      │   devolve: message, preferences, shouldSavePreferences
      ▼
  extraiu preferências? ──sim──► savePreferences ── valida e une ao perfil (código puro, sem LLM)
      │ não                            │
      ▼                                ▼
  janela cheia? (6, 12, 18... mensagens) ──sim──► summarize ── SummaryAgent (LLM) consolida o perfil
      │ não                                            │
      ▼                                                ▼
{ conversationId, reply, extractedPreferences, preferencesSaved, summarized, profile }
```

A resposta da API é o **estado final do grafo** em JSON. Chave ausente = nó que não rodou:

```json
{
  "message": "Oi! Me chamo Júlia, tenho 30 anos e sou fã de comédia romântica",
  "conversationId": "01a14592-774b-7292-b45c-9db5e563439c",
  "reply": "Oi, Júlia! Comédia romântica é perfeita para relaxar. Já viu o filme \"Podres de Ricos\" (2018)?",
  "extractedPreferences": { "name": "Júlia", "age": 30, "favoriteGenres": ["comédia romântica"] },
  "needsSummarization": false,
  "preferencesSaved": true,
  "profile": { "name": "Júlia", "age": 30, "favoriteGenres": ["comédia romântica"] }
}
```

### Detalhes da memória

- **Histórico como mensagens de verdade.** O SDK reenvia a conversa ao modelo como mensagens
  `user`/`assistant`. O prompt do usuário é só o que ele digitou (é isso que fica gravado), e todas
  as regras ficam no system prompt.
- **Janela + resumo, sem apagar nada.** A transcrição inteira fica no banco, mas o modelo recebe só as
  últimas 6 mensagens (`maxConversationMessages()`). A cada 6 mensagens o `summarize` consolida essa
  janela no perfil. Como a janela tem o mesmo tamanho do intervalo do resumo, tudo o que sai da janela
  já foi resumido.
- **Listas só crescem.** Gêneros, títulos, pessoas, assistidos e "não gostei" são unidos ao que já
  existe, sem duplicar (`Terror` e `terror` contam como um). O resumo reescreve só os textos
  `keyPreferences` e `importantContext`.
- **Humor é memória curta.** "Hoje estou triste" muda a recomendação desta conversa, mas não vai para
  o perfil.
- **O LLM extrai, o código valida.** Idade fora de 1–120, lista que veio como texto etc. são
  descartadas antes de gravar (`preferencesError` na resposta).
- **Cada um com sua conversa.** O SDK continua qualquer conversa pelo ID. O `ChatRequest` confere se
  ela é do usuário do token e, se não for, responde 404.

### Regras de recomendação (no prompt)

- Nunca recomendar o que está em `watched`, `disliked` ou `favoriteTitles` (a pessoa já viu).
- Idade menor que 18: só títulos com classificação indicativa compatível.
- Sempre título, ano e se é filme ou série.
- Nunca afirmar em qual streaming um título está (o modelo não conhece o catálogo atual).

## Onde está cada coisa

| Arquivo | Papel |
|---|---|
| `app/Graph/RecommendationGraph.php` | **Comece por aqui.** O grafo: estado, nós e as arestas condicionais |
| `app/Ai/Agents/ChatAgent.php` | Prompt + schema da conversa. Memória curta via `RemembersConversations` |
| `app/Ai/Agents/SummaryAgent.php` | Prompt + schema que consolida a janela de mensagens no perfil |
| `app/Ai/Concerns/RoutesThroughOpenRouter.php` | Modelos, temperatura e roteamento do OpenRouter (compartilhado pelos agents) |
| `app/Services/TasteProfileService.php` | Regras de gravação do perfil (união das listas, o que é sobrescrito) |
| `app/Models/TasteProfile.php` | O perfil (memória longa) e o formato em que os agents o recebem |
| `app/Console/Commands/ChatCommand.php` | Chat interativo no terminal (`sail artisan chat`) |
| `app/Http/Controllers/ChatController.php` | Recebe a mensagem, roda o grafo, devolve o estado em JSON |
| `app/Http/Requests/ChatRequest.php` | Validação da entrada e verificação do dono da conversa |
| `routes/api.php` | `POST /api/chat`, protegida por `auth:sanctum` |
| `config/movies.php` | Modelos, roteamento, temperaturas e tamanho da janela |
| `config/ai.php` | Provider OpenRouter e configuração das conversas do SDK |
| `database/seeders/DatabaseSeeder.php` | Usuários Ana (sem perfil) e Bruno (com perfil) + tokens |
| `tests/Feature/ChatTest.php` | Testes offline da API (agents simulados com `::fake()`) |
| `tests/Feature/ChatCommandTest.php` | Testes offline do comando de terminal |
| `tests/Feature/LiveChatTest.php` | Testes chamando o OpenRouter de verdade |

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
./vendor/bin/sail artisan migrate --seed   # cria o SQLite, os usuários e mostra os tokens
```

O seeder imprime um token para cada usuário. Guarde-os: o banco só tem o hash, e eles não aparecem
de novo.

```
🔑 Token de Ana: 1|AbC...
🔑 Token de Bruno: 2|XyZ...
```

Para recomeçar do zero (e gerar tokens novos): `./vendor/bin/sail artisan migrate:fresh --seed`.

> Se a porta 80 estiver ocupada (outro projeto do lab com Sail de pé, Herd, Valet...),
> pare o outro (`sail stop`) ou defina `APP_PORT=8080` no `.env`.

## Usando

### No terminal

```bash
./vendor/bin/sail artisan chat --user=ana           # Ana ainda não tem perfil: o agente se apresenta
./vendor/bin/sail artisan chat --user=bruno         # Bruno já tem perfil: o agente já chega recomendando
./vendor/bin/sail artisan chat --user=ana --resume  # continua a última conversa da Ana
./vendor/bin/sail artisan chat --user=carla         # usuário novo é criado na hora
```

Converse com a Ana, saia (`sair`) e entre de novo: é uma conversa nova (memória curta vazia), mas o
agente lembra o que ela contou (memória longa).

### Pela API

```bash
TOKEN='1|AbC...'   # token da Ana, impresso pelo seeder

# primeira mensagem: abre uma conversa
curl -X POST http://localhost/api/chat \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"message": "Oi! Me chamo Ana, tenho 30 anos e amo terror"}'

# continua a mesma conversa: mande de volta o conversationId da resposta
curl -X POST http://localhost/api/chat \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"message": "Já vi Invocação do Mal. Me indica outro?", "conversation_id": "<conversationId>"}'
```

Para acompanhar cada nó do grafo rodando, abra outro terminal:

```bash
./vendor/bin/sail artisan pail
# 💬 Chatting...
# ✅ Reply generated: Oi, Ana! ...
# 💾 Saving preferences...
# ✅ Preferences saved
# 🧠 Summarizing conversation...
# ✅ Profile consolidated
```

## Configurando

- **Trocar os modelos:** edite `models` em `config/movies.php`. Os dois agents pedem saída
  estruturada, então só servem modelos com `structured_outputs`
  ([lista dos gratuitos](https://openrouter.ai/models?max_price=0&supported_parameters=structured_outputs)).
  Suportar o formato não garante extrair bem: rode os testes `live` antes de adotar um modelo.
- **Janela de memória:** `window` em `config/movies.php` (padrão 6 mensagens = 3 trocas).
- **Temperaturas:** `temperature` (conversa, 0.7) e `summary_temperature` (resumo, 0.2).
- **Título das conversas:** `config/ai.php` desliga o `generate_title` do SDK, que faria uma chamada
  extra ao LLM por conversa nova.

## Testes

```bash
./vendor/bin/sail artisan test                # suíte padrão: offline, não gasta créditos
./vendor/bin/sail artisan test --group=live   # chama o OpenRouter de verdade (precisa da chave)
```

Os testes offline simulam o que os agents responderam (`ChatAgent::fake([...])`) e verificam o resto:
roteamento do grafo, validação, união das listas, resumo, dono da conversa e autenticação. Mesmo com os
agents simulados, a memória curta é real: o SDK grava as conversas no SQLite em memória. Um teste usa
`Http::fake()` para conferir a requisição enviada ao OpenRouter: o histórico indo como mensagens
`user`/`assistant`, o JSON schema e o roteamento.

Os testes `live` cobrem extrair e lembrar numa conversa nova, lembrar dentro da mesma conversa e
consolidar o perfil com o resumo.
