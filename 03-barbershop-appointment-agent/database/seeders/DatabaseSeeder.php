<?php

namespace Database\Seeders;

use App\Enums\Service;
use App\Models\Barber;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Dados iniciais: 3 barbeiros e 2 agendamentos (hoje 11h e amanhã 14h),
 * para já existir horário ocupado e algo para cancelar ao testar.
 *
 * As datas são relativas ao momento em que o seeder roda. Para "resetar"
 * a agenda: sail artisan migrate:fresh --seed
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $joao = Barber::create(['name' => 'João Navalha']);
        $pedro = Barber::create(['name' => 'Pedro Tesoura']);
        Barber::create(['name' => 'Rafa Degradê']);

        $todayAtElevenAM = today()->setTime(11, 0);
        $tomorrowAtTwoPM = today()->addDay()->setTime(14, 0);

        $joao->appointments()->create([
            'customer_name' => 'Joao da Silva',
            'service' => Service::Haircut,
            'starts_at' => $todayAtElevenAM,
            'ends_at' => $todayAtElevenAM->copy()->addMinutes(Service::Haircut->minutes()),
        ]);

        $pedro->appointments()->create([
            'customer_name' => 'Luana Costa',
            'service' => Service::Beard,
            'starts_at' => $tomorrowAtTwoPM,
            'ends_at' => $tomorrowAtTwoPM->copy()->addMinutes(Service::Beard->minutes()),
        ]);
    }
}
