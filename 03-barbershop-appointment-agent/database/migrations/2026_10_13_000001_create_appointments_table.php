<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agendamentos. Cada linha ocupa o intervalo starts_at → ends_at na agenda
 * de um barbeiro.
 *
 * A agenda precisa ficar no banco porque no PHP cada requisição começa do
 * zero: um agendamento criado numa requisição só pode ser cancelado na
 * seguinte se estiver persistido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barber_id')->constrained()->cascadeOnDelete();
            $table->string('customer_name');
            $table->string('service');

            // Guardamos início E fim para checar sobreposição de horários
            // direto na query (ver AppointmentService::checkAvailability).
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
