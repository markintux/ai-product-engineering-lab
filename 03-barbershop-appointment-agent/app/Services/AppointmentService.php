<?php

namespace App\Services;

use App\Enums\Service;
use App\Models\Appointment;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * Regras de negócio da agenda: expediente, conflito de horário,
 * cancelamento e horários livres.
 *
 * Aqui não tem LLM nenhum: é código determinístico. O AppointmentGraph só
 * chama estes métodos depois que o IntentAgent extraiu os dados da
 * mensagem e eles passaram pela validação.
 *
 * Quando uma regra é violada, lança DomainException com a mensagem em
 * português. O nó do grafo captura, marca actionSuccess = false, e o
 * ReplyAgent usa essa mensagem para explicar o problema ao cliente.
 */
class AppointmentService
{
    /**
     * Procura um agendamento que começa exatamente nesse horário.
     */
    public function findAppointment(int $barberId, Carbon $startsAt, ?string $customerName = null): ?Appointment
    {
        return Appointment::query()
            ->where('barber_id', $barberId)
            ->where('starts_at', $startsAt)
            ->when($customerName, fn ($query) => $query->where('customer_name', $customerName))
            ->first();
    }

    /**
     * O barbeiro está livre durante todo o serviço?
     *
     * Como cada serviço tem duração, não basta comparar o horário de
     * início: checamos SOBREPOSIÇÃO de intervalos. Dois intervalos se
     * sobrepõem quando cada um começa antes do outro terminar:
     *
     *   existente:      11:00 |-------| 11:30
     *   novo:     10:30 |-----------| 11:20      → sobrepõe
     *   novo:                         11:30 |--| → não sobrepõe
     */
    public function checkAvailability(int $barberId, Carbon $startsAt, Service $service): bool
    {
        $endsAt = $startsAt->copy()->addMinutes($service->minutes());

        return Appointment::query()
            ->where('barber_id', $barberId)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->doesntExist();
    }

    /**
     * Cria o agendamento se o horário estiver dentro do expediente e livre.
     *
     * @throws DomainException
     */
    public function bookAppointment(int $barberId, Carbon $startsAt, string $customerName, Service $service): Appointment
    {
        $this->ensureIsOpen($startsAt, $service);

        if (! $this->checkAvailability($barberId, $startsAt, $service)) {
            throw new DomainException('Horário indisponível para este barbeiro');
        }

        return Appointment::create([
            'barber_id' => $barberId,
            'customer_name' => $customerName,
            'service' => $service,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes($service->minutes()),
        ]);
    }

    /**
     * Remove o agendamento do cliente nesse horário.
     *
     * Devolve o agendamento removido para o ReplyAgent saber o que foi
     * cancelado (serviço, horário). Sem isso o LLM tende a inventar.
     *
     * @throws DomainException
     */
    public function cancelAppointment(int $barberId, string $customerName, Carbon $startsAt): Appointment
    {
        $appointment = $this->findAppointment($barberId, $startsAt, $customerName);

        if (! $appointment) {
            throw new DomainException('Agendamento não encontrado para cancelamento');
        }

        $appointment->delete();

        return $appointment;
    }

    /**
     * Horários livres do barbeiro no dia, considerando a duração do serviço.
     * Usado pelo intent "list_slots".
     *
     * Percorre o expediente de slot_minutes em slot_minutes e mantém só os
     * horários em que o serviço inteiro cabe antes do fechamento e não
     * colide com outro agendamento.
     *
     * @return list<string> Horários no formato "HH:MM"
     *
     * @throws DomainException
     */
    public function availableSlots(int $barberId, Carbon $day, Service $service): array
    {
        if ($day->isSunday()) {
            throw new DomainException('A barbearia não abre aos domingos');
        }

        $slot = $day->copy()->setTimeFromTimeString(config('barbershop.opens_at'));
        $closesAt = $day->copy()->setTimeFromTimeString(config('barbershop.closes_at'));

        $slots = [];

        while ($slot->copy()->addMinutes($service->minutes())->lte($closesAt)) {
            if ($this->checkAvailability($barberId, $slot, $service)) {
                $slots[] = $slot->format('H:i');
            }

            $slot->addMinutes(config('barbershop.slot_minutes'));
        }

        return $slots;
    }

    /**
     * Regra de expediente: segunda a sábado, e o serviço precisa começar
     * e terminar dentro do horário de funcionamento.
     *
     * @throws DomainException
     */
    private function ensureIsOpen(Carbon $startsAt, Service $service): void
    {
        if ($startsAt->isSunday()) {
            throw new DomainException('A barbearia não abre aos domingos');
        }

        $opensAt = $startsAt->copy()->setTimeFromTimeString(config('barbershop.opens_at'));
        $closesAt = $startsAt->copy()->setTimeFromTimeString(config('barbershop.closes_at'));
        $endsAt = $startsAt->copy()->addMinutes($service->minutes());

        if ($startsAt->lt($opensAt) || $endsAt->gt($closesAt)) {
            throw new DomainException(sprintf(
                'Fora do horário de funcionamento (das %s às %s)',
                config('barbershop.opens_at'),
                config('barbershop.closes_at'),
            ));
        }
    }
}
