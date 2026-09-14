<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Client;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Enums\RoleName;
use App\Support\Enums\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Todo acesso nasce de um convite com papel pré-definido (Seção 6.1). */
class InvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_convite_valido_cria_usuario_com_papel_e_vinculo(): void
    {
        $this->seedRoles();

        $client = Client::factory()->configured()->create();
        $plain = Invitation::generateToken();

        Invitation::create([
            'token' => Invitation::hashToken($plain),
            'email' => 'novo@cliente.test',
            'name' => 'Novo Aprovador',
            'type' => UserType::Client,
            'role' => RoleName::ClientAdmin,
            'client_id' => $client->getKey(),
            'expires_at' => now()->addDays(7),
        ]);

        $this->get(route('invitation.show', $plain))->assertOk();

        $this->post(route('invitation.accept', $plain), [
            'name' => 'Novo Aprovador',
            'password' => 'senha-muito-boa',
            'password_confirmation' => 'senha-muito-boa',
        ])->assertRedirect(route('portal.dashboard'));

        $user = User::where('email', 'novo@cliente.test')->firstOrFail();

        $this->assertTrue($user->hasRole(RoleName::ClientAdmin->value));
        $this->assertTrue($user->canAccessClient($client));
        $this->assertSame(UserType::Client, $user->type);
    }

    public function test_convite_expirado_devolve_404(): void
    {
        $this->seedRoles();

        $plain = Invitation::generateToken();

        Invitation::create([
            'token' => Invitation::hashToken($plain),
            'email' => 'tarde@demais.test',
            'type' => UserType::Agency,
            'role' => RoleName::Criador,
            'expires_at' => now()->subDay(),
        ]);

        $this->get(route('invitation.show', $plain))->assertNotFound();
    }

    public function test_token_invalido_devolve_404(): void
    {
        $this->get(route('invitation.show', str_repeat('x', 64)))->assertNotFound();
    }

    public function test_token_em_claro_nunca_e_gravado_no_banco(): void
    {
        $plain = Invitation::generateToken();

        $invitation = Invitation::create([
            'token' => Invitation::hashToken($plain),
            'email' => 'alguem@teste.test',
            'type' => UserType::Agency,
            'role' => RoleName::Criador,
            'expires_at' => now()->addDay(),
        ]);

        $this->assertNotSame($plain, $invitation->token);
        $this->assertDatabaseMissing('invitations', ['token' => $plain]);
    }
}
