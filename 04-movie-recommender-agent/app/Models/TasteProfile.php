<?php

namespace App\Models;

use Database\Factories\TasteProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Memória longa do usuário: o gosto dele para filmes e séries.
 *
 * Os agents trabalham com chaves em camelCase (favoriteGenres), que é o
 * formato dos schemas de saída estruturada. O banco usa snake_case
 * (favorite_genres). LIST_FIELDS faz essa ponte para os campos de lista.
 */
#[Fillable([
    'name', 'age', 'favorite_genres', 'favorite_titles', 'favorite_people',
    'watched', 'disliked', 'watching_context', 'key_preferences', 'important_context',
])]
class TasteProfile extends Model
{
    /** @use HasFactory<TasteProfileFactory> */
    use HasFactory;

    /**
     * Campos de lista: chave usada pelos agents => coluna no banco.
     */
    public const LIST_FIELDS = [
        'favoriteGenres' => 'favorite_genres',
        'favoriteTitles' => 'favorite_titles',
        'favoritePeople' => 'favorite_people',
        'watched' => 'watched',
        'disliked' => 'disliked',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'age' => 'integer',
            'favorite_genres' => 'array',
            'favorite_titles' => 'array',
            'favorite_people' => 'array',
            'watched' => 'array',
            'disliked' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * O perfil no formato que os agents recebem (e que a API devolve):
     * chaves em camelCase, iguais às dos schemas, sem os campos vazios.
     *
     * @return array<string, mixed>
     */
    public function forPrompt(): array
    {
        return array_filter([
            'name' => $this->name,
            'age' => $this->age,
            'favoriteGenres' => $this->favorite_genres,
            'favoriteTitles' => $this->favorite_titles,
            'favoritePeople' => $this->favorite_people,
            'watched' => $this->watched,
            'disliked' => $this->disliked,
            'watchingContext' => $this->watching_context,
            'keyPreferences' => $this->key_preferences,
            'importantContext' => $this->important_context,
        ], filled(...));
    }
}
