<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Client;
use App\Models\User;
use App\Support\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        RateLimiter::clear('');
    }

    public function test_tela_de_login_responde(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Entrar');
    }

    public function test_usuario_da_agencia_entra_e_cai_no_painel(): void
    {
        $user = $this->userWithRole(RoleName::Gestor, Client::factory()->configured()->create());

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('painel.dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_usuario_do_cliente_entra_e_cai_no_portal(): void
    {
        $user = $this->userWithRole(RoleName::ClientAdmin, Client::factory()->configured()->create());

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('portal.dashboard'));
    }

    public function test_senha_errada_nao_autentica(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'senha-errada',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_usuario_desativado_nao_entra(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_tem_limite_de_cinco_tentativas_por_minuto(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'errada',
            ]);
        }

        // A sexta tentativa é barrada mesmo com a senha correta, e a mensagem
        // diz em quantos segundos liberar — erro sempre acionável (Seção 14).
        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString(
            'Muitas tentativas',
            session('errors')->first('email'),
        );
    }

    public function test_registro_publico_nao_existe(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    public function test_area_autenticada_redireciona_visitante_para_o_login(): void
    {
        $this->get(route('painel.dashboard'))->assertRedirect(route('login'));
        $this->get(route('portal.dashboard'))->assertRedirect(route('login'));
    }

    public function test_logout_encerra_a_sessao(): void
    {
        $user = $this->userWithRole(RoleName::Gestor, Client::factory()->configured()->create());

        $this->actingAsUser($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_raiz_leva_cada_tipo_de_usuario_para_o_seu_lugar(): void
    {
        $this->get('/')->assertRedirect(route('login'));

        $gestor = $this->userWithRole(RoleName::Gestor, Client::factory()->configured()->create());
        $this->actingAsUser($gestor)->get('/')->assertRedirect(route('painel.dashboard'));
    }
}
