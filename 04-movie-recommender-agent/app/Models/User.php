<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * O usuário é quem conversa com o agente.
 *
 * - HasApiTokens (Sanctum): permite criar tokens para chamar a API.
 * - tasteProfile: a memória longa dele (gosto para filmes e séries).
 * - As conversas (memória curta) ficam nas tabelas do Laravel AI SDK,
 *   ligadas ao usuário pelas colunas participant_type/participant_id.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return HasOne<TasteProfile, $this>
     */
    public function tasteProfile(): HasOne
    {
        return $this->hasOne(TasteProfile::class);
    }
}
