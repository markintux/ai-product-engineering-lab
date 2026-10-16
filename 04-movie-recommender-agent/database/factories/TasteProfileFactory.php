<?php

namespace Database\Factories;

use App\Models\TasteProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TasteProfile>
 */
class TasteProfileFactory extends Factory
{
    /**
     * Um perfil típico de quem curte ficção científica.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->firstName(),
            'age' => fake()->numberBetween(18, 70),
            'favorite_genres' => ['Ficção científica'],
            'favorite_titles' => ['Interestelar'],
            'favorite_people' => ['Christopher Nolan'],
            'watched' => ['A Origem'],
            'disliked' => ['Lost'],
            'watching_context' => 'Sozinho, à noite',
            'key_preferences' => 'Gosta de ficção científica com roteiro complexo.',
        ];
    }
}
