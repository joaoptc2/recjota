<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\Invitation;
use App\Models\User;
use App\Notifications\UserInvited;
use App\Support\DataObjects\InvitationData;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Cria um convite e envia o link de aceite (Seção 6.1). O token em claro
 * vive só no e-mail: o banco guarda o hash, e um convite pendente para o
 * mesmo e-mail é revogado antes de nascer outro.
 */
class InviteUser
{
    public function __invoke(InvitationData $dados): Invitation
    {
        $email = mb_strtolower(trim($dados->email));

        if (User::withTrashed()->where('email', $email)->exists()) {
            throw new DomainException('Já existe um usuário com este e-mail. Se ele foi desativado, reative-o na lista de usuários.');
        }

        if ($dados->role->userType()->value === 'client' && $dados->clientId === null) {
            throw new DomainException('Convite para papel de cliente precisa indicar o cliente.');
        }

        $plain = Invitation::generateToken();

        $convite = DB::transaction(function () use ($dados, $email, $plain): Invitation {
            Invitation::query()
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            return Invitation::create([
                'token' => Invitation::hashToken($plain),
                'email' => $email,
                'name' => $dados->name,
                'type' => $dados->role->userType(),
                'role' => $dados->role,
                'client_id' => $dados->clientId,
                'invited_by' => $dados->invitedBy,
                'expires_at' => now()->addDays($dados->validDays),
            ]);
        });

        Notification::route('mail', $email)->notify(new UserInvited($convite, $plain));

        return $convite;
    }
}
