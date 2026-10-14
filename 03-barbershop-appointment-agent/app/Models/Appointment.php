<?php

namespace App\Models;

use App\Enums\Service;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um horário reservado na agenda de um barbeiro.
 *
 * created_at/updated_at ficam fora do JSON (appointmentData): não ajudam o
 * ReplyAgent e sairiam em UTC, misturados com os horários locais.
 */
#[Fillable(['barber_id', 'customer_name', 'service', 'starts_at', 'ends_at'])]
#[Hidden(['created_at', 'updated_at'])]
class Appointment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service' => Service::class,

            // O formato depois de ":" só vale para o JSON de saída. Sem ele
            // o Laravel devolveria em UTC ("...T19:00:00.000000Z"), o que
            // confunde quando o horário local é 16:00.
            'starts_at' => 'datetime:Y-m-d H:i',
            'ends_at' => 'datetime:Y-m-d H:i',
        ];
    }

    /**
     * @return BelongsTo<Barber, $this>
     */
    public function barber(): BelongsTo
    {
        return $this->belongsTo(Barber::class);
    }
}
