# Barbershop Appointment Agent (Laravel)

Assistente de agendamento de uma barbearia: o cliente escreve em linguagem natural
("sou Maria, quero um corte com o João amanhã às 16h") e o sistema **agenda**, **cancela**
ou **mostra os horários livres**, respondendo com uma mensagem amigável.

**Stack:** Laravel 13 · [Laravel AI SDK](https://laravel.com/docs/ai-sdk) · Laravel Boost · Sail (Docker) · Pest

---

## Como funciona

A ideia central: **o LLM entende, o código decide e executa, o LLM responde.**

```
POST /api/chat  {"question": "Sou Maria, quero um corte com o João amanhã às 16h"}
      │
      ▼
identifyIntent ── IntentAgent (LLM + saída estruturada)
      │             intent: schedule | cancel | list_slots | unknown
      │             extrai: barberId ("João" → 1), datetime ("amanhã 16h" → 2026-10-14 16:00),
      │                     service, customerName
      ▼
   match (intent) ─────────────── roteamento: o intent decide qual ação roda
      ├─ schedule ──► AppointmentService::bookAppointment()    ┐
      ├─ cancel ────► AppointmentService::cancelAppointment()  │ código puro, sem LLM:
      ├─ list_slots ► AppointmentService::availableSlots()     │ valida e aplica as regras
      └─ unknown ───┐                                          ┘
      ▼             ▼
message ───────── ReplyAgent (LLM): recebe o cenário ("schedule_success", "cancel_error"...)
      │             e escreve a resposta para o cliente
      ▼
{ "messages": [...], "intent": "schedule", "actionSuccess": true, "appointmentData": {...} }
```

A resposta da API é o **estado final do grafo** em JSON: o histórico de mensagens, o intent,
os dados extraídos e o resultado da ação.

### Regras da agenda

| Regra | Erro devolvido |
|---|---|
| Barbearia abre de segunda a sábado, das 09:00 às 18:00 | `A barbearia não abre aos domingos` / `Fora do horário de funcionamento (das 09:00 às 18:00)` |
| Cada serviço tem duração (corte 30min, barba 20min, corte+barba 50min) e não pode **sobrepor** outro horário do barbeiro | `Horário indisponível para este barbeiro` |
| Só cancela se existir o agendamento daquele cliente, naquele barbeiro e horário | `Agendamento não encontrado para cancelamento` |

Como cada serviço tem duração, o conflito não é "mesmo horário de início", e sim
**sobreposição de intervalos** (ver `AppointmentService::checkAvailability`).

## Onde está cada coisa

| Arquivo | Papel |
|---|---|
| `app/Graph/AppointmentGraph.php` | **Comece por aqui.** O grafo: estado, nós e a aresta condicional |
| `app/Ai/Agents/IntentAgent.php` | Prompt + schema que classifica a intenção e extrai os dados |
| `app/Ai/Agents/ReplyAgent.php` | Prompt + schema que escreve a resposta ao cliente |
| `app/Ai/Concerns/RoutesThroughOpenRouter.php` | Modelos, temperatura e roteamento do OpenRouter (compartilhado pelos agents) |
| `app/Services/AppointmentService.php` | Regras da agenda (expediente, sobreposição, cancelamento, horários livres) |
| `app/Enums/Service.php` | Serviços e suas durações |
| `app/Models/Barber.php`, `Appointment.php` | Barbeiros e agendamentos (SQLite) |
| `database/seeders/DatabaseSeeder.php` | 3 barbeiros + 2 agendamentos iniciais |
| `config/barbershop.php` | Modelos candidatos, critério de roteamento, temperatura, expediente |
| `app/Http/Controllers/ChatController.php` | Recebe a pergunta, roda o grafo, devolve o estado em JSON |
| `app/Http/Requests/ChatRequest.php` | Validação da entrada (`question` com ≥ 10 caracteres) |
| `tests/Feature/ChatTest.php` | Testes offline (agents simulados com `::fake()`) |
| `tests/Feature/LiveAppointmentTest.php` | Testes chamando o OpenRouter de verdade |

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
./vendor/bin/sail artisan migrate --seed   # cria o SQLite com barbeiros e agenda inicial
```

> Se a porta 80 estiver ocupada (outro projeto do lab com Sail de pé, Herd, Valet...),
> pare o outro (`sail stop`) ou defina `APP_PORT=8080` no `.env`.

Os agendamentos iniciais são relativos ao dia em que o seeder rodou ("hoje 11h", "amanhã 14h").
Para resetar a agenda: `./vendor/bin/sail artisan migrate:fresh --seed`.

## Usando

```bash
# agendar
curl -X POST http://localhost/api/chat \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"question": "Sou Maria Santos e quero um corte com o João Navalha amanhã às 16h"}'

# ver horários livres
curl -X POST http://localhost/api/chat \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"question": "Quais horários o Rafa tem livres amanhã para corte e barba?"}'

# cancelar (agendamento criado pelo seeder)
curl -X POST http://localhost/api/chat \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"question": "Sou Joao da Silva, cancela meu corte com o João Navalha hoje às 11h"}'
```

Para acompanhar cada nó do grafo rodando, abra outro terminal:

```bash
./vendor/bin/sail artisan pail
# 🔍 Identifying intent...
# ✅ Intent identified: schedule
# 📅 Scheduling appointment...
# ✅ Appointment scheduled successfully
# 💬 Generating response message...
```

## Configurando

- **Trocar os modelos:** edite `models` em `config/barbershop.php`. Os dois agents pedem saída
  estruturada, então só servem modelos com `structured_outputs`
  ([lista dos gratuitos](https://openrouter.ai/models?max_price=0&supported_parameters=structured_outputs)).
  Modelos `:free` entram e saem com frequência: se a chamada falhar, troque o ID.
- **Expediente:** `opens_at`, `closes_at` e `slot_minutes` em `config/barbershop.php`.
- **Timezone:** `America/Sao_Paulo` (`config/app.php`). O LLM recebe a data atual e devolve
  o horário local no formato `YYYY-MM-DD HH:MM`.

## Testes

```bash
./vendor/bin/sail artisan test                # suíte padrão: offline, não gasta créditos
./vendor/bin/sail artisan test --group=live   # chama o OpenRouter de verdade (precisa da chave)
```

Os testes offline simulam o que o IntentAgent "entendeu" (`IntentAgent::fake([...])`) e
verificam o resto do grafo: roteamento, validação, regras da agenda e o que chega ao
ReplyAgent. Um deles usa `Http::fake()` para conferir a requisição enviada ao OpenRouter
(JSON schema, modelos, critério de roteamento).

Os testes `live` cobrem agendar, agendar e cancelar, e listar horários livres, com as datas
reais de hoje. São pulados automaticamente quando o dia cai num domingo.
