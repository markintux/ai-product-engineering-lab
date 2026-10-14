<?php

namespace App\Enums;

/**
 * Serviços da barbearia.
 *
 * Cada serviço tem uma duração, então um agendamento ocupa um intervalo
 * na agenda do barbeiro (início → início + minutes()). É essa duração que
 * o AppointmentService usa para checar conflitos e horários livres.
 *
 * O "value" ('corte', 'barba', 'corte_barba') é o que o IntentAgent
 * devolve no campo "service" e o que fica gravado no banco.
 */
enum Service: string
{
    case Haircut = 'corte';
    case Beard = 'barba';
    case HaircutAndBeard = 'corte_barba';

    /**
     * Quanto tempo o serviço ocupa na agenda do barbeiro.
     */
    public function minutes(): int
    {
        return match ($this) {
            self::Haircut => 30,
            self::Beard => 20,
            self::HaircutAndBeard => 50,
        };
    }

    /**
     * Nome legível, enviado aos prompts.
     */
    public function label(): string
    {
        return match ($this) {
            self::Haircut => 'Corte',
            self::Beard => 'Barba',
            self::HaircutAndBeard => 'Corte + Barba',
        };
    }
}
