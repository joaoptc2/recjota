<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Approvals\IssueApprovalLink;
use App\Models\Approval;
use App\Models\User;
use App\Notifications\ApprovalDeadlineApproaching;
use App\Support\Enums\ApprovalStatus;
use App\Support\Enums\RoleName;
use App\Support\Enums\UserType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Lembrete de prazo de aprovação (Seção 6.6 / 8.2).
 *
 * Roda de hora em hora. Cada pedido é lembrado UMA vez — `reminded_at` é o que
 * impede o sistema de virar spam e o cliente de parar de ler os e-mails.
 */
class RemindPendingApprovals extends Command
{
    protected $signature = 'approvals:remind {--horas=24 : Janela de antecedência do lembrete}';

    protected $description = 'Avisa os aprovadores cujo prazo está por vencer';

    public function handle(TenantContext $tenant, IssueApprovalLink $emitirLink): int
    {
        $horas = (int) $this->option('horas');

        // Comando de console não tem usuário: o escopo de tenant é suspenso
        // explicitamente, nunca por acidente (Seção 4.1).
        $enviados = $tenant->withoutRestriction(function () use ($horas, $emitirLink): int {
            $pendentes = Approval::query()
                ->where('status', ApprovalStatus::Pending->value)
                ->whereNull('reminded_at')
                ->whereNotNull('due_at')
                ->whereBetween('due_at', [now(), now()->addHours($horas)])
                ->with(['post.client'])
                ->get();

            $total = 0;

            foreach ($pendentes as $aprovacao) {
                $post = $aprovacao->post;

                if ($post === null) {
                    continue;
                }

                $aprovadores = $post->client->users()
                    ->where('users.type', UserType::Client->value)
                    ->where('users.is_active', true)
                    ->wherePivot('role', RoleName::ClientAdmin->value)
                    ->get();

                foreach ($aprovadores as $aprovador) {
                    /** @var User $aprovador */
                    $link = $emitirLink->forPost($post, $aprovador->email, $aprovador->name);
                    $aprovador->notify(new ApprovalDeadlineApproaching($aprovacao, $link['url']));
                    $total++;
                }

                $aprovacao->forceFill(['reminded_at' => now()])->save();
            }

            return $total;
        });

        $this->info(sprintf('%d lembrete(s) enviado(s).', $enviados));

        return self::SUCCESS;
    }
}
