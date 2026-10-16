# 🧪 AI Product Engineering Lab

**Hands-on applied AI, from neural networks trained in JavaScript to LLM agents with routing,
orchestration and memory, built the way they would ship to production.**

![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![Laravel AI SDK](https://img.shields.io/badge/Laravel_AI_SDK-1.x-FF2D20?logo=laravel&logoColor=white)
![OpenRouter](https://img.shields.io/badge/OpenRouter-LLM_routing-6366F1)
![TensorFlow.js](https://img.shields.io/badge/TensorFlow.js-4.22-FF6F00?logo=tensorflow&logoColor=white)
![Pest](https://img.shields.io/badge/tests-Pest-F28D1A)
![Docker](https://img.shields.io/badge/Docker-Laravel_Sail-2496ED?logo=docker&logoColor=white)

---

## About

This lab is where I turn my Applied AI Engineering studies into working software. Each project takes
one concept (a neural network, a recommender, LLM routing, an agent graph, conversational memory) and
implements it as a small application that runs and has tests.

The goal is not to reproduce examples, but to understand how ML and LLM techniques fit into real
products: validated inputs, deterministic business rules around the model, authentication,
configuration, and test suites that run without spending a single token.

## Learning path

```
00  Neural network classification         TensorFlow.js
 │
 ▼
01  ML-based recommendation               TensorFlow.js · Web Workers
 │
 ▼
02  LLM model routing                     Laravel AI SDK · OpenRouter
 │
 ▼
03  Agent graph + structured output       intent → deterministic actions → reply
 │
 ▼
04  Agent memory                          short-term conversation + long-term profile
 ┆
 ▼
Next  RAG                                 embeddings + vector search
```

## Projects

| # | Project | What it does | Key concepts | Stack | Status |
|---|---|---|---|---|---|
| 00 | [Customer Churn Classifier](./00-customer-churn-classifier) | Classifies customers into low, medium or high churn risk | Neural networks, normalization, softmax classification | Node.js · TensorFlow.js | ✅ |
| 01 | [Personalized Pub Recommender](./01-personalized-product-recommender) | Pub menu and customer purchase history feeding a recommendation model | Purchase-based recommendation, training in a Web Worker | JavaScript · TensorFlow.js · tfjs-vis | 🚧 |
| 02 | [Smart Model Router Gateway](./02-smart-model-router-gateway) | HTTP gateway where OpenRouter picks the model by price, throughput or latency | LLM routing, provider sorting, multi-model candidates | Laravel · Laravel AI SDK · OpenRouter | ✅ |
| 03 | [Barbershop Appointment Agent](./03-barbershop-appointment-agent) | Books, cancels and lists free slots from natural-language messages | Intent classification, structured outputs, agent graph | Laravel · Laravel AI SDK · OpenRouter | ✅ |
| 04 | [Movie Recommender Agent](./04-movie-recommender-agent) | Recommends movies and series, and remembers the user across conversations | Short and long-term memory, summarization, extraction | Laravel · Laravel AI SDK · OpenRouter · Sanctum | ✅ |

### 04 · Movie Recommender Agent

A conversational recommender with **two memories**.

- **Short-term memory:** the Laravel AI SDK persists every conversation and replays it as real
  `user`/`assistant` messages, capped to a sliding context window.
- **Long-term memory:** a per-user taste profile (genres, titles, directors and actors, watched, disliked)
  built from structured extraction on every message.
- **Summarization:** each time a conversation fills a window, a summarizer agent consolidates it into the
  profile. The transcript is never deleted.
- **Production concerns:** Sanctum authentication, conversation ownership checks, and LLM output
  validated before it is persisted.
- **Two interfaces:** a REST API and an interactive terminal chat (`artisan chat`).

### 03 · Barbershop Appointment Agent

- An intent agent classifies `schedule`, `cancel`, `list_slots` or `unknown` and extracts booking data
  through JSON Schema structured output.
- A state graph routes to deterministic action nodes: business hours, interval-overlap detection and
  cancellation rules.
- A reply agent turns the action result into a customer-facing message.
- Core principle: **the LLM understands, the code decides and executes.**

### 02 · Smart Model Router Gateway

- Sends a list of candidate models plus a routing criterion (price, throughput or latency) to
  OpenRouter, which picks the best model for each request.
- Model lists and criteria live in configuration, and every response reports which model won.

### 01 · Personalized Pub Recommender *(in progress)*

- Browser app with customer profiles, purchase history and the pub menu.
- Model training runs in a Web Worker with TensorFlow.js and tfjs-vis. The UI and data are in place;
  the training logic is the next step.

### 00 · Customer Churn Classifier

- Feed-forward network (Dense 16 ReLU → Dense 3 softmax) trained with Adam and categorical
  cross-entropy.
- Min-Max feature normalization, one-hot labels and inference on new customers, over an intentionally
  small synthetic dataset.

## What this lab covers

| Area | Topics |
|---|---|
| **Machine learning** | Neural networks, feature normalization, multiclass classification, training in Node.js and in the browser |
| **LLM integration** | Structured outputs (JSON Schema), JSON-formatted prompts, few-shot examples, per-task temperature |
| **Model operations** | OpenRouter routing by price, throughput or latency, multi-model candidates, model evaluation with live tests |
| **Agents** | Intent classification, graph orchestration with conditional edges, deterministic action nodes |
| **Memory** | Conversation persistence, sliding context window, summarization, long-term user profiles |
| **Engineering** | Validating LLM output, authentication and resource ownership, Dockerized environments, offline tests with faked agents plus opt-in live suites |

## Engineering principles

- **The LLM interprets, the code decides.** Models extract and write text; business rules and database
  writes stay in deterministic, testable code.
- **Never trust model output.** Every extracted field is validated before it reaches the database.
- **Tests that don't burn tokens.** Default suites fake the agents; `--group=live` runs against real
  models.
- **Measure before trusting a model.** Supporting structured outputs doesn't guarantee good extraction.
  In project 04, live tests rejected two free models that returned valid JSON with data in the wrong
  fields.
- **Configuration over code.** Models, routing criteria, temperatures and memory windows live in
  config files.

## Tech stack

| Layer | Technologies |
|---|---|
| **Main stack** | PHP 8.5 · Laravel 13 · Blade · Alpine.js |
| **AI** | Laravel AI SDK · OpenRouter · TensorFlow.js · tfjs-vis |
| **Infrastructure** | Docker (Laravel Sail) · SQLite · Laravel Sanctum |
| **Quality** | Pest · Laravel Pint · Laravel Boost (MCP) |
| **Planned** | RAG (embeddings + vector search) · Python |

## Running the projects

Each project has its own README with setup, usage and tests.

- **Laravel projects (02–04):** Docker + Laravel Sail. Install dependencies with the Sail Composer
  image, set `OPENROUTER_API_KEY` in `.env`, then `sail up -d` and `sail artisan migrate`.
- **JavaScript projects (00–01):** `npm install` and `npm start`.

## Repository structure

```
ai-product-engineering-lab/
├── 00-customer-churn-classifier/        # TensorFlow.js neural network (Node.js)
├── 01-personalized-product-recommender/ # TensorFlow.js recommender (browser)
├── 02-smart-model-router-gateway/       # LLM routing gateway (Laravel)
├── 03-barbershop-appointment-agent/     # Agent graph with structured output (Laravel)
└── 04-movie-recommender-agent/          # Agent with conversational memory (Laravel)
```
