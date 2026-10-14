<?php

namespace App\Graph;

use App\Ai\Agents\IntentAgent;
use App\Ai\Agents\ReplyAgent;
use App\Enums\Service;
use App\Services\AppointmentService;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Orquestra o atendimento: entende a mensagem, executa a ação e responde.
 *
 *   START → identifyIntent ─┬─ schedule ───┐
 *                           ├─ cancel ─────┤
 *                           ├─ listSlots ──┤
 *                           └─ (unknown) ──┴→ message → END
 *
 * Como funciona:
 *
 *   - ESTADO: um array que passa por todos os nós e acumula o que cada um
 *     produz. Chaves: messages, intent, barberId, barberName, service,
 *     datetime, customerName, actionSuccess, actionError, appointmentData,
 *     availableSlots, error.
 *
 *   - NÓ: um método que recebe o estado e devolve SÓ as chaves que mudou.
 *
 *   - MERGE: [...$state, ...$parcial] junta o que o nó devolveu ao estado
 *     (chaves novas entram, chaves repetidas são sobrescritas).
 *
 *   - ROTEAMENTO: um match no intent decide qual nó de ação roda.
 *
 * Os nós de LLM (identifyIntent e message) só interpretam e escrevem
 * texto. Quem altera a agenda são os nós de ação, com código
 * determinístico e validação.
 */
class AppointmentGraph
{
    public function __construct(private AppointmentService $appointmentService) {}

    /**
     * Roda o grafo inteiro para uma mensagem do cliente.
     *
     * @return array<string, mixed> O estado final, devolvido como JSON pela API.
     */
    public function invoke(string $question): array
    {
        $state = [
            'messages' => [
                ['role' => 'user', 'content' => $question],
            ],
        ];

        // START → identifyIntent
        $state = [...$state, ...$this->identifyIntent($state)];

        // Roteamento: o intent decide qual nó de ação roda. "unknown" (ou
        // erro no identifyIntent) não executa ação e vai direto ao message.
        $state = [...$state, ...match ($state['intent'] ?? 'unknown') {
            'schedule' => $this->schedule($state),
            'cancel' => $this->cancel($state),
            'list_slots' => $this->listSlots($state),
            default => [],
        }];

        // schedule / cancel / listSlots → message → END
        return [...$state, ...$this->message($state)];
    }

    /**
     * Nó "identifyIntent": o LLM lê a última mensagem e devolve o intent
     * e os dados extraídos (barberId, datetime...), que entram no estado.
     *
     * Se o LLM falhar, o fluxo não quebra: vira "unknown" com o erro
     * guardado em "error", e o nó message pede desculpas ao cliente.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function identifyIntent(array $state): array
    {
        Log::info('🔍 Identifying intent...');

        $question = Arr::last($state['messages'])['content'];

        try {
            $response = (new IntentAgent)->prompt(IntentAgent::userPrompt($question));

            Log::info("✅ Intent identified: {$response['intent']}");

            return $response->toArray();
        } catch (Throwable $e) {
            Log::error("❌ Error in identifyIntent node: {$e->getMessage()}");

            return [
                'intent' => 'unknown',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Nó "schedule": valida os dados extraídos e agenda. Nenhum LLM aqui,
     * só regra de negócio.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function schedule(array $state): array
    {
        Log::info('📅 Scheduling appointment...');

        // Os dados vieram de um LLM: podem faltar campos ou vir valores
        // inventados. A regra "exists" garante que o barbeiro existe.
        $validator = Validator::make($state, [
            'barberId' => ['required', 'integer', 'exists:barbers,id'],
            'datetime' => ['required', 'date_format:Y-m-d H:i'],
            'customerName' => ['required', 'string'],
            'service' => ['nullable', Rule::enum(Service::class)],
        ]);

        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->all());
        }

        try {
            $appointment = $this->appointmentService->bookAppointment(
                $state['barberId'],
                Carbon::parse($state['datetime']),
                $state['customerName'],
                // Sem serviço informado na mensagem, assume corte.
                Service::tryFrom($state['service'] ?? '') ?? Service::Haircut,
            );

            Log::info('✅ Appointment scheduled successfully');

            return [
                'actionSuccess' => true,
                'appointmentData' => $appointment->toArray(),
            ];
        } catch (DomainException $e) {
            return $this->actionFailed('Scheduling', $e);
        }
    }

    /**
     * Nó "cancel": valida os dados extraídos e remove o agendamento.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function cancel(array $state): array
    {
        Log::info('❌ Cancelling appointment...');

        $validator = Validator::make($state, [
            'barberId' => ['required', 'integer', 'exists:barbers,id'],
            'datetime' => ['required', 'date_format:Y-m-d H:i'],
            'customerName' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->all());
        }

        try {
            $appointment = $this->appointmentService->cancelAppointment(
                $state['barberId'],
                $state['customerName'],
                Carbon::parse($state['datetime']),
            );

            Log::info('✅ Appointment cancelled successfully');

            // O agendamento removido vai para o estado para o ReplyAgent
            // saber o que foi cancelado (serviço, horário). A mensagem de
            // cancelamento normalmente não cita o serviço, e sem esse dado
            // o modelo tende a inventar um.
            return [
                'actionSuccess' => true,
                'appointmentData' => $appointment->toArray(),
            ];
        } catch (DomainException $e) {
            return $this->actionFailed('Cancellation', $e);
        }
    }

    /**
     * Nó "listSlots": consulta os horários livres do barbeiro no dia.
     * Só lê a agenda, não altera nada.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function listSlots(array $state): array
    {
        Log::info('🗓️  Listing available slots...');

        $validator = Validator::make($state, [
            'barberId' => ['required', 'integer', 'exists:barbers,id'],
            'datetime' => ['required', 'date_format:Y-m-d H:i'],
            'service' => ['nullable', Rule::enum(Service::class)],
        ]);

        if ($validator->fails()) {
            return $this->validationFailed($validator->errors()->all());
        }

        try {
            $slots = $this->appointmentService->availableSlots(
                $state['barberId'],
                Carbon::parse($state['datetime'])->startOfDay(),
                Service::tryFrom($state['service'] ?? '') ?? Service::Haircut,
            );

            Log::info('✅ Found '.count($slots).' available slots');

            return [
                'actionSuccess' => true,
                'availableSlots' => $slots,
            ];
        } catch (DomainException $e) {
            return $this->actionFailed('Listing slots', $e);
        }
    }

    /**
     * Nó "message": o LLM transforma o resultado em texto para o cliente,
     * e a resposta entra no histórico "messages".
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function message(array $state): array
    {
        Log::info('💬 Generating response message...');

        // Cenário que o ReplyAgent conhece: "<intent>_success",
        // "<intent>_error" ou "unknown".
        $intent = $state['intent'] ?? 'unknown';
        $scenario = $intent === 'unknown'
            ? 'unknown'
            : $intent.'_'.(($state['actionSuccess'] ?? false) ? 'success' : 'error');

        // O ReplyAgent só sabe o que estiver aqui: dados extraídos,
        // resultado da ação e erros. O que ficar de fora, o modelo pode
        // inventar.
        $details = Arr::only($state, [
            'barberName', 'service', 'datetime', 'customerName',
            'appointmentData', 'availableSlots', 'actionError', 'error',
        ]);

        try {
            $response = (new ReplyAgent)->prompt(ReplyAgent::userPrompt($scenario, $details));
            $reply = $response['message'];

            Log::info("✅ Message generated: {$reply}");
        } catch (Throwable $e) {
            Log::error("❌ Error in message node: {$e->getMessage()}");

            $reply = 'Desculpe, não consegui processar sua mensagem agora. Tente novamente em instantes.';
        }

        return [
            'messages' => [
                ...$state['messages'],
                ['role' => 'assistant', 'content' => $reply],
            ],
        ];
    }

    /**
     * Saída padrão quando os dados extraídos não passam na validação.
     * Os erros viram actionError e o ReplyAgent explica o que faltou.
     *
     * @param  list<string>  $errors
     * @return array{actionSuccess: false, actionError: string}
     */
    private function validationFailed(array $errors): array
    {
        $message = implode(', ', $errors);

        Log::warning("⚠️  Validation failed: {$message}");

        return [
            'actionSuccess' => false,
            'actionError' => $message,
        ];
    }

    /**
     * Saída padrão quando uma regra de negócio barra a ação
     * (horário ocupado, fora do expediente, agendamento não encontrado).
     *
     * @return array{actionSuccess: false, actionError: string}
     */
    private function actionFailed(string $action, DomainException $e): array
    {
        Log::warning("❌ {$action} failed: {$e->getMessage()}");

        return [
            'actionSuccess' => false,
            'actionError' => $e->getMessage(),
        ];
    }
}
