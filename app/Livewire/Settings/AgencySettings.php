<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Actions\Settings\SaveAgencyBranding;
use App\Actions\System\RetryFailedJob;
use App\Actions\Users\InviteUser;
use App\Actions\Users\ToggleUserActive;
use App\Models\Invitation;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\SystemHeartbeat;
use App\Models\User;
use App\Support\DataObjects\AgencyBrandingData;
use App\Support\DataObjects\InvitationData;
use App\Support\Enums\RoleName;
use App\Support\Enums\UserType;
use App\Support\Settings;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Configurações da agência (Seção 11.2): branding, usuários, convites e
 * saúde do sistema. Toda ação passa por uma Policy e por uma Action; o
 * componente só valida entrada e mostra resultado.
 */
class AgencySettings extends Component
{
    /** Minutos sem batimento a partir dos quais o cron é dado como parado. */
    public const CRON_PARADO_MINUTOS = 20;

    public string $agencyName = '';

    public string $supportEmail = '';

    public string $primaryColor = '';

    public string $inviteName = '';

    public string $inviteEmail = '';

    public string $inviteRole = 'gestor';

    public ?string $feedback = null;

    public ?string $saidaRetry = null;

    public function mount(Settings $settings): void
    {
        $this->authorize('viewAny', Setting::class);

        $this->agencyName = (string) $settings->get('agency.name');
        $this->supportEmail = (string) $settings->get('agency.support_email');
        $this->primaryColor = (string) $settings->get('agency.primary_color');
    }

    /* ---------------------------------------------------------------- Branding */

    public function saveBranding(SaveAgencyBranding $salvar): void
    {
        $this->authorize('create', Setting::class);

        $dados = $this->validate([
            'agencyName' => ['required', 'string', 'max:80'],
            'supportEmail' => ['required', 'email', 'max:255'],
            'primaryColor' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], [
            'agencyName.required' => 'Informe o nome da agência.',
            'supportEmail.required' => 'Informe o e-mail de suporte.',
            'supportEmail.email' => 'O e-mail de suporte não parece válido.',
            'primaryColor.required' => 'Escolha a cor primária.',
            'primaryColor.regex' => 'A cor precisa estar no formato #RRGGBB, por exemplo #4F46E5.',
        ]);

        $salvar(new AgencyBrandingData(
            name: trim($dados['agencyName']),
            supportEmail: mb_strtolower(trim($dados['supportEmail'])),
            primaryColor: $dados['primaryColor'],
        ));

        $this->primaryColor = strtoupper($this->primaryColor);
        $this->feedback = 'Dados da agência salvos. O nome e a cor valem a partir da próxima página aberta.';
    }

    /* ---------------------------------------------------------------- Usuários */

    /** @return Collection<int, User> */
    #[Computed]
    public function users(): Collection
    {
        return User::query()
            ->with('roles')
            ->where('type', UserType::Agency->value)
            ->orderBy('name')
            ->get();
    }

    public function toggleUser(int $userId, ToggleUserActive $alternar): void
    {
        $alvo = User::query()->where('type', UserType::Agency->value)->findOrFail($userId);
        $this->authorize('update', $alvo);

        try {
            $alternar($alvo, auth()->user());
        } catch (DomainException $e) {
            $this->addError('users', $e->getMessage());

            return;
        }

        $this->feedback = $alvo->is_active
            ? sprintf('%s reativado(a).', $alvo->name)
            : sprintf('%s desativado(a). Ele(a) não consegue mais entrar; nada foi apagado.', $alvo->name);

        unset($this->users);
    }

    /* ---------------------------------------------------------------- Convites */

    /** @return array<string, string> Papéis de agência que podem ser convidados daqui. */
    public function invitableRoles(): array
    {
        $papeis = [];

        foreach (RoleName::agencyRoles() as $papel) {
            // Só quem é owner cria outro owner.
            if ($papel === RoleName::Owner && ! auth()->user()->hasRole(RoleName::Owner->value)) {
                continue;
            }

            $papeis[$papel->value] = $papel->label();
        }

        return $papeis;
    }

    /** @return Collection<int, Invitation> */
    #[Computed]
    public function pendingInvitations(): Collection
    {
        return Invitation::query()
            ->with(['client', 'inviter'])
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->get();
    }

    public function invite(InviteUser $convidar): void
    {
        $this->authorize('create', Invitation::class);

        $dados = $this->validate([
            'inviteName' => ['nullable', 'string', 'max:255'],
            'inviteEmail' => ['required', 'email', 'max:255'],
            'inviteRole' => ['required', 'in:'.implode(',', array_keys($this->invitableRoles()))],
        ], [
            'inviteEmail.required' => 'Informe o e-mail de quem vai receber o convite.',
            'inviteEmail.email' => 'O e-mail do convite não parece válido.',
            'inviteRole.in' => 'Escolha um papel válido.',
        ]);

        try {
            $convite = $convidar(new InvitationData(
                email: $dados['inviteEmail'],
                role: RoleName::from($dados['inviteRole']),
                name: filled($dados['inviteName']) ? trim($dados['inviteName']) : null,
                invitedBy: auth()->id(),
            ));
        } catch (DomainException $e) {
            $this->addError('inviteEmail', $e->getMessage());

            return;
        }

        $this->reset('inviteName', 'inviteEmail');
        $this->feedback = sprintf('Convite enviado para %s. O link vale por 7 dias.', $convite->email);

        unset($this->pendingInvitations);
    }

    public function revokeInvitation(int $invitationId): void
    {
        $convite = Invitation::findOrFail($invitationId);
        $this->authorize('revoke', $convite);

        $convite->forceFill(['revoked_at' => now()])->save();

        $this->feedback = sprintf('Convite de %s cancelado. O link deixou de funcionar.', $convite->email);

        unset($this->pendingInvitations);
    }

    /* ---------------------------------------------------------------- Saúde */

    #[Computed]
    public function heartbeat(): ?SystemHeartbeat
    {
        return SystemHeartbeat::firstWhere('name', 'scheduler');
    }

    public function cronParado(): bool
    {
        return $this->heartbeat()?->isStale(self::CRON_PARADO_MINUTOS) ?? true;
    }

    #[Computed]
    public function failedJobsCount(): int
    {
        return DB::table('failed_jobs')->count();
    }

    /** @return BaseCollection<int, object> */
    #[Computed]
    public function failedJobs(): BaseCollection
    {
        return DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(10)
            ->get()
            ->map(function (object $job): object {
                $payload = json_decode((string) $job->payload, true);
                $job->nome = is_array($payload) ? ($payload['displayName'] ?? 'job') : 'job';
                $job->resumo = strtok((string) $job->exception, "\n") ?: 'sem mensagem';

                return $job;
            });
    }

    #[Computed]
    public function queueSize(): int
    {
        return DB::table('jobs')->count();
    }

    /** @return Collection<int, SocialAccount> */
    #[Computed]
    public function expiringAccounts(): Collection
    {
        return SocialAccount::query()
            ->with('client')
            ->connected()
            ->expiringWithin(7)
            ->orderBy('token_expires_at')
            ->get();
    }

    public function retryFailedJob(string $uuid, RetryFailedJob $reenfileirar): void
    {
        $this->authorize('create', Setting::class);

        $this->saidaRetry = $reenfileirar($uuid);

        unset($this->failedJobs, $this->failedJobsCount, $this->queueSize);
    }

    public function render(): View
    {
        return view('livewire.settings.agency-settings');
    }
}
