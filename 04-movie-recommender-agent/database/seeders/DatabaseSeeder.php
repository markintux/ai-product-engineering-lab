<?php

namespace Database\Seeders;

use App\Models\TasteProfile;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Dois usuários para testar os dois caminhos da memória longa:
 *
 *   - Ana:   ainda não contou nada. O agente se apresenta e pergunta o gosto dela.
 *   - Bruno: já tem perfil. O agente cumprimenta citando o que sabe e já recomenda.
 *
 * Também cria um token Sanctum para cada um, mostrado no terminal. O token
 * só aparece agora: o banco guarda apenas o hash dele. Para gerar tokens
 * novos: sail artisan migrate:fresh --seed
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $ana = User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);

        $bruno = User::factory()->create(['name' => 'Bruno', 'email' => 'bruno@example.com']);

        TasteProfile::factory()->for($bruno)->create([
            'name' => 'Bruno',
            'age' => 34,
            'favorite_genres' => ['Ficção científica', 'Suspense'],
            'favorite_titles' => ['Interestelar'],
            'favorite_people' => ['Denis Villeneuve'],
            'watched' => ['Duna', 'A Chegada'],
            'disliked' => ['Lost'],
            'watching_context' => 'Com a namorada, nas sextas à noite',
            'key_preferences' => 'Gosta de ficção científica com roteiro complexo e visual marcante. Prefere filmes a séries longas.',
        ]);

        foreach ([$ana, $bruno] as $user) {
            $token = $user->createToken('api')->plainTextToken;

            $this->command?->line("🔑 Token de {$user->name}: {$token}");
        }
    }
}
