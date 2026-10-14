<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um barbeiro e seus agendamentos.
 *
 * A lista de barbeiros vai no system prompt do IntentAgent, para o modelo
 * converter o nome citado pelo cliente ("o João") no id.
 */
#[Fillable(['name'])]
class Barber extends Model
{
    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
