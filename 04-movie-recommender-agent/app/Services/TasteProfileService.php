<?php

namespace App\Services;

use App\Models\TasteProfile;
use App\Models\User;

/**
 * Grava a memória longa (TasteProfile). Código puro, sem LLM.
 *
 * Duas portas de entrada, uma para cada nó do grafo:
 *
 *   - merge():        preferências extraídas de UMA mensagem (nó savePreferences)
 *   - storeSummary(): o resumo de uma janela de mensagens (nó summarize)
 *
 * Regra comum: listas nunca perdem itens. O que chega é unido ao que já
 * existe, sem duplicar ("Terror" e "terror" contam como um só). Assim um
 * resumo que esqueceu um gênero não apaga o que foi salvo antes.
 */
class TasteProfileService
{
    /**
     * Junta ao perfil as preferências de uma mensagem.
     *
     * @param  array<string, mixed>  $preferences  Já validadas pelo grafo.
     */
    public function merge(User $user, array $preferences): TasteProfile
    {
        $profile = $this->apply($user, $preferences);
        $profile->save();

        return $profile;
    }

    /**
     * Grava o perfil consolidado pelo SummaryAgent.
     *
     * Listas e dados simples passam pela mesma união do merge(). Os textos
     * (keyPreferences e importantContext) são reescritos: o SummaryAgent
     * recebeu a versão anterior e devolve a atualizada.
     *
     * @param  array<string, mixed>  $summary  Já validado pelo grafo.
     */
    public function storeSummary(User $user, array $summary): TasteProfile
    {
        $profile = $this->apply($user, $summary);
        $profile->key_preferences = $summary['keyPreferences'];
        $profile->important_context = $summary['importantContext'] ?? $profile->important_context;
        $profile->save();

        return $profile;
    }

    /**
     * Aplica os dados no perfil do usuário (ou num perfil novo), sem salvar.
     *
     * @param  array<string, mixed>  $data
     */
    private function apply(User $user, array $data): TasteProfile
    {
        $profile = $user->tasteProfile()->firstOrNew();

        foreach (TasteProfile::LIST_FIELDS as $field => $column) {
            $profile->{$column} = $this->union($profile->{$column} ?? [], $data[$field] ?? []);
        }

        // Dados simples: o valor novo vence; sem valor novo, fica o atual.
        $profile->name = $data['name'] ?? $profile->name;
        $profile->age = $data['age'] ?? $profile->age;
        $profile->watching_context = $data['watchingContext'] ?? $profile->watching_context;

        return $profile;
    }

    /**
     * Une duas listas mantendo a ordem e a grafia da primeira ocorrência.
     *
     * @param  list<string>  $current
     * @param  list<string>  $new
     * @return list<string>
     */
    private function union(array $current, array $new): array
    {
        return collect([...$current, ...$new])
            ->map(fn (string $item) => trim($item))
            ->filter()
            ->unique(fn (string $item) => mb_strtolower($item))
            ->values()
            ->all();
    }
}
