<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\RoutesThroughOpenRouter;
use App\Enums\Service;
use App\Models\Barber;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Lê a mensagem do cliente, classifica a intenção e extrai os dados do
 * agendamento (barbeiro, serviço, data/hora, nome).
 *
 * O prompt tem três partes:
 *   - instructions(): system prompt (papel, barbeiros, serviços, data atual, regras, exemplos)
 *   - userPrompt():   a mensagem do cliente + instruções de extração
 *   - schema():       o formato obrigatório da resposta
 *
 * Saída estruturada: com HasStructuredOutput, o SDK envia ao OpenRouter
 * um "response_format" do tipo json_schema montado a partir de schema().
 * O modelo é obrigado a responder um JSON nesse formato, e o SDK devolve
 * a resposta como array: $response['intent'], $response['barberId']...
 *
 * Os prompts são montados como JSON (json_encode): chaves explícitas
 * ajudam o modelo a separar regras, dados e exemplos. Ficam em inglês,
 * mas o modelo entende mensagens em português normalmente.
 */
#[Provider(Lab::OpenRouter)]
class IntentAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable, RoutesThroughOpenRouter;

    /**
     * System prompt: quem o modelo é, a lista de barbeiros e serviços, a
     * data atual (para entender "amanhã", "sexta"...), as regras de cada
     * intenção e exemplos.
     *
     * A lista de barbeiros vem do banco: é com ela que o modelo converte
     * "o João" no barberId 1. Os exemplos usam datas calculadas a partir
     * de agora, para mostrar ao modelo o formato certo com datas coerentes.
     */
    public function instructions(): string
    {
        $now = now();
        $tomorrowAtFourPM = $now->copy()->addDay()->setTime(16, 0);
        $todayAtTwoPM = $now->copy()->setTime(14, 0);
        $nextFriday = $now->copy()->next('Friday');

        return json_encode([
            'role' => 'Intent Classifier for Barbershop Appointments',
            'task' => 'Identify user intent and extract all appointment-related details',
            'barbers' => Barber::query()->get(['id', 'name'])->toArray(),
            'services' => array_map(fn (Service $service) => [
                'id' => $service->value,
                'name' => $service->label(),
                'minutes' => $service->minutes(),
            ], Service::cases()),
            'current_date' => $now->format('Y-m-d H:i'),
            'current_weekday' => $now->englishDayOfWeek,
            'rules' => [
                'schedule' => [
                    'description' => 'User wants to book/schedule a new appointment',
                    'keywords' => ['agendar', 'marcar', 'reservar', 'quero cortar', 'schedule', 'book'],
                    'required_fields' => ['barberId', 'datetime', 'customerName'],
                    'optional_fields' => ['service'],
                ],
                'cancel' => [
                    'description' => 'User wants to cancel an existing appointment',
                    'keywords' => ['cancelar', 'desmarcar', 'remover', 'cancel'],
                    'required_fields' => ['barberId', 'datetime', 'customerName'],
                ],
                'list_slots' => [
                    'description' => 'User wants to know which times are available for a barber on a given day',
                    'keywords' => ['horários livres', 'que horas tem', 'disponível', 'vaga', 'available'],
                    'required_fields' => ['barberId', 'datetime'],
                    'optional_fields' => ['service'],
                ],
                'unknown' => [
                    'description' => 'Anything not related to scheduling, cancelling or checking available times',
                    'examples' => ['weather questions', 'general info', 'unrelated queries'],
                ],
            ],
            'extraction_instructions' => [
                'barberId' => 'Match the barber name mentioned in the question to the ID from the barbers list. Use fuzzy matching.',
                'barberName' => 'Extract the barber name as mentioned by the user',
                'service' => 'Match the requested service to one of the service ids. Omit if not mentioned.',
                'datetime' => 'Parse relative dates (hoje, amanhã, sexta) and times. Use current_date as reference. Format: YYYY-MM-DD HH:MM in local time, without timezone. For list_slots, use the requested day with time 00:00.',
                'customerName' => 'Extract the customer name from the question',
            ],
            'examples' => [
                [
                    'input' => 'Sou Maria Santos e quero agendar um corte com o João Navalha amanhã às 16h',
                    'output' => ['intent' => 'schedule', 'barberId' => 1, 'barberName' => 'João Navalha', 'service' => 'corte', 'datetime' => $tomorrowAtFourPM->format('Y-m-d H:i'), 'customerName' => 'Maria Santos'],
                ],
                [
                    'input' => 'Cancela meu horário com o Pedro hoje às 14h, me chamo Luana Costa',
                    'output' => ['intent' => 'cancel', 'barberId' => 2, 'barberName' => 'Pedro', 'datetime' => $todayAtTwoPM->format('Y-m-d H:i'), 'customerName' => 'Luana Costa'],
                ],
                [
                    'input' => 'Quais horários o Rafa tem livres na sexta para corte e barba?',
                    'output' => ['intent' => 'list_slots', 'barberId' => 3, 'barberName' => 'Rafa', 'service' => 'corte_barba', 'datetime' => $nextFriday->format('Y-m-d 00:00')],
                ],
                [
                    'input' => 'Qual a previsão do tempo hoje?',
                    'output' => ['intent' => 'unknown'],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Formato obrigatório da resposta. Só "intent" é required: o modelo
     * devolve apenas os campos presentes na mensagem. Quem cobra os campos
     * obrigatórios de cada ação é o nó do grafo, com o Validator.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'intent' => $schema->string()
                ->enum(['schedule', 'cancel', 'list_slots', 'unknown'])
                ->description('The user intent')
                ->required(),
            'barberId' => $schema->integer()->description('ID of the barber'),
            'barberName' => $schema->string()->description('Name of the barber'),
            'service' => $schema->string()
                ->enum(array_column(Service::cases(), 'value'))
                ->description('Requested service id'),
            'datetime' => $schema->string()->description('Appointment date and time, format YYYY-MM-DD HH:MM'),
            'customerName' => $schema->string()->description('Customer name extracted from question'),
        ];
    }

    /**
     * Mensagem enviada como "user", também em JSON: a pergunta original do
     * cliente + as instruções de extração.
     */
    public static function userPrompt(string $question): string
    {
        return json_encode([
            'question' => $question,
            'instructions' => [
                'Carefully analyze the question to determine the user intent',
                'Extract all relevant appointment details',
                'Convert dates and times to the YYYY-MM-DD HH:MM format',
                'Match barber names to their IDs and services to their ids',
                'Return only the fields that are present in the question',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
