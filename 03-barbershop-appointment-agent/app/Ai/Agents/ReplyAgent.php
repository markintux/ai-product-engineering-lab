<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\RoutesThroughOpenRouter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Transforma o resultado da ação numa mensagem amigável para o cliente.
 *
 * O modelo NÃO decide nada aqui: recebe o cenário já definido pelo grafo
 * ("schedule_success", "cancel_error"...) e os detalhes (barbeiro,
 * horário, motivo do erro...), e só escreve o texto.
 *
 *   - instructions(): papel, tom e o que dizer em cada cenário
 *   - userPrompt():   cenário + detalhes + exemplos de resposta
 *   - schema():       resposta obrigatória no formato { "message": "..." }
 */
#[Provider(Lab::OpenRouter)]
class ReplyAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable, RoutesThroughOpenRouter;

    public function instructions(): string
    {
        return json_encode([
            'role' => 'Friendly Barbershop Receptionist',
            'task' => 'Generate clear, friendly and professional messages for customers',
            'tone' => 'Friendly and relaxed, yet professional, clear and concise',
            'guidelines' => [
                'language' => 'Use simple, everyday language',
                'format' => 'Clear and concise, avoid jargon',
                'personalization' => 'Include relevant details (names, barber, service, dates, times)',
                'empathy' => 'Acknowledge the customer, especially for errors',
            ],
            'scenarios' => [
                'schedule_success' => 'Confirm the appointment with all details',
                'schedule_error' => 'Apologize and explain why scheduling failed',
                'cancel_success' => 'Confirm the cancellation',
                'cancel_error' => 'Apologize and explain why cancellation failed',
                'list_slots_success' => 'List the available times for the barber on that day',
                'list_slots_error' => 'Apologize and explain why the available times could not be listed',
                'unknown' => 'Politely explain you can only help with scheduling, cancelling or checking available times',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Uma única string com no mínimo 10 caracteres, para evitar resposta vazia.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'message' => $schema->string()
                ->min(10)
                ->description('Clear, friendly message for the customer')
                ->required(),
        ];
    }

    /**
     * @param  string  $scenario  Ex.: "schedule_success", "cancel_error", "unknown"
     * @param  array<string, mixed>  $details  Dados do estado do grafo (barbeiro, horário, erro...)
     */
    public static function userPrompt(string $scenario, array $details): string
    {
        return json_encode([
            'scenario' => $scenario,
            'details' => $details,
            'instructions' => [
                'Generate an appropriate message for the given scenario',
                'Include all relevant details from the details object',
                'Be clear and direct',
                'Show empathy, especially for errors',
                'For unknown intents, guide users back to scheduling, cancelling or checking available times',
                'Answer in Portuguese (pt-BR)',
            ],
            'examples' => [
                'schedule_success' => 'Fechado, Maria! Seu corte com o João Navalha está confirmado para 14 de outubro às 16h. Te esperamos!',
                'schedule_error' => 'Poxa, esse horário já está ocupado com o João Navalha. Quer tentar outro horário? Posso te mostrar os horários livres.',
                'cancel_success' => 'Pronto, Luana! Seu horário de barba com o Pedro Tesoura em 14 de outubro às 14h foi cancelado.',
                'cancel_error' => 'Não encontrei nenhum agendamento com esses dados. Confere o barbeiro, o dia e o horário?',
                'list_slots_success' => 'O Rafa Degradê tem estes horários livres na sexta: 09:00, 10:30, 14:00 e 16:30. Qual fica melhor?',
                'list_slots_error' => 'A barbearia não abre aos domingos. Quer ver os horários de segunda?',
                'unknown' => 'Por aqui eu consigo agendar, cancelar ou mostrar horários livres. Como posso te ajudar?',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
