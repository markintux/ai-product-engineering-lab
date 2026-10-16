<?php

namespace App\Console\Commands;

use App\Ai\Agents\ChatAgent;
use App\Graph\RecommendationGraph;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

/**
 * Chat interativo no terminal, rodando o mesmo grafo da API.
 *
 *   sail artisan chat --user=ana            # conversa nova
 *   sail artisan chat --user=ana --resume   # continua a última conversa
 *
 * No terminal não há login: quem roda o comando já tem acesso ao servidor.
 * O --user só diz de quem é a memória (e cria o usuário se ele não existir).
 *
 * Cada execução sem --resume é uma conversa nova (memória curta vazia), mas
 * o perfil (memória longa) é o mesmo: é assim que dá para ver o agente
 * lembrando da pessoa entre uma conversa e outra.
 */
#[Signature('chat
    {--user= : Nome do usuário (é criado se não existir)}
    {--resume : Continua a última conversa em vez de começar outra}')]
#[Description('Conversa no terminal com o recomendador de filmes e séries')]
class ChatCommand extends Command
{
    public function handle(RecommendationGraph $graph): int
    {
        $name = $this->option('user') ?? text('Qual o seu nome de usuário?', required: true);

        $user = User::firstOrCreate(
            ['email' => Str::slug($name).'@example.com'],
            ['name' => Str::title($name), 'password' => Str::password()],
        );

        $this->line(str_repeat('═', 60));
        $this->line('  🎬 Recomendador de Filmes e Séries com Memória');
        $this->line(str_repeat('═', 60));
        $this->line("👤 Usuário: {$user->name}");

        $conversationId = $this->option('resume')
            ? (new ChatAgent)->continueLastConversation($user)->currentConversation()
            : null;

        if ($conversationId !== null) {
            $this->line("💬 Continuando a conversa {$conversationId}");
        } else {
            // Abertura: quem já tem perfil é cumprimentado com o que o agente
            // sabe dele; quem não tem é convidado a contar do que gosta.
            $opening = $user->tasteProfile()->exists()
                ? 'Inicie a conversa de forma casual mencionando o que você sabe sobre mim e me recomende um filme ou série!'
                : 'Olá! Se apresente de forma amigável e pergunte meu nome e do que eu gosto de assistir.';

            $conversationId = $this->turn($graph, $user, $opening, null);
        }

        $this->line('Digite suas mensagens abaixo. Digite "sair" para encerrar.');

        while (true) {
            $message = text('Você', required: true);

            if (in_array(Str::lower(trim($message)), ['sair', 'exit'], true)) {
                $this->line('👋 Até mais!');

                return self::SUCCESS;
            }

            $conversationId = $this->turn($graph, $user, $message, $conversationId);
        }
    }

    /**
     * Roda o grafo para uma mensagem, mostra a resposta e devolve o ID da
     * conversa, para a próxima mensagem continuar nela.
     */
    private function turn(RecommendationGraph $graph, User $user, string $message, ?string $conversationId): ?string
    {
        $state = spin(fn () => $graph->invoke($user, $message, $conversationId), 'Pensando...');

        $this->newLine();
        $this->line("🎬 {$state['reply']}");

        if ($state['preferencesSaved'] ?? false) {
            $this->comment('   💾 Preferências salvas no seu perfil');
        }

        if ($state['summarized'] ?? false) {
            $this->comment('   🧠 Perfil consolidado com o resumo da conversa');
        }

        $this->newLine();

        return $state['conversationId'];
    }
}
